<?php
/**
 * Lightweight Prometheus metrics backed by Redis.
 *
 * 의존성 없이(PHP 7.2 호환) 기존 포크의 Redis 계측 패턴(PF_ESI_METRICS_*, PF_ACTIVE_WORKERS)을
 * 일반화한 헬퍼. 모든 관측값은 단일 Redis hash(PF_METRICS)에 누적되고,
 * MetricsController(/metrics)가 Prometheus text format으로 렌더링한다.
 *
 * 저장 포맷 (hash field 인코딩):
 *   c|<name>|<labels>            counter
 *   g|<name>|<labels>            gauge
 *   hb|<name>|<le>|<labels>      histogram bucket (non-cumulative, 렌더 시 누적 변환)
 *   hs|<name>|<labels>           histogram sum
 *   hc|<name>|<labels>           histogram count
 *   <labels> = 'k1="v1",k2="v2"' (키 정렬, '|' 및 '"' 제거)
 *
 * 계측 실패가 요청을 깨뜨리지 않도록 모든 public 메서드는 fail-silent.
 */

namespace Exodus4D\Pathfinder\Lib;

class Metrics {

    /**
     * Redis hash key holding all metric samples
     */
    const REDIS_KEY = 'PF_METRICS';

    /**
     * default latency buckets (seconds) for HTTP request timings
     */
    const BUCKETS_HTTP = [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    /**
     * latency buckets (seconds) for DB queries
     */
    const BUCKETS_DB = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 5];

    /**
     * latency buckets (seconds) for outbound ESI / socket calls
     */
    const BUCKETS_OUTBOUND = [0.05, 0.1, 0.25, 0.5, 1, 1.5, 2, 3, 5, 10];

    /**
     * @var \Redis|null|false false = uninitialized, null = unavailable
     */
    private static $redis = false;

    /**
     * @return \Redis|null
     */
    protected static function getRedis() : ?\Redis {
        if(self::$redis === false){
            self::$redis = null;
            if(extension_loaded('redis')){
                $redis = new \Redis();
                try {
                    if($redis->pconnect(
                        Config::getEnvironmentData('REDIS_HOST'),
                        Config::getEnvironmentData('REDIS_PORT') ? : 6379,
                        Config::REDIS_OPT_TIMEOUT
                    )){
                        if($auth = Config::getEnvironmentData('REDIS_AUTH')){
                            $redis->auth($auth);
                        }
                        self::$redis = $redis;
                    }
                } catch (\Throwable $e) {
                    self::$redis = null;
                }
            }
        }
        return self::$redis;
    }

    /**
     * increment a counter metric
     * @param string $name
     * @param array $labels
     * @param int $value
     */
    public static function counter(string $name, array $labels = [], int $value = 1) : void {
        try {
            if($redis = self::getRedis()){
                $redis->hIncrBy(self::REDIS_KEY, 'c|' . $name . '|' . self::labelString($labels), $value);
            }
        } catch (\Throwable $e) {
            // metrics must never break the request
        }
    }

    /**
     * set a gauge metric
     * @param string $name
     * @param array $labels
     * @param float $value
     */
    public static function gauge(string $name, array $labels, float $value) : void {
        try {
            if($redis = self::getRedis()){
                $redis->hSet(self::REDIS_KEY, 'g|' . $name . '|' . self::labelString($labels), $value);
            }
        } catch (\Throwable $e) {
            // metrics must never break the request
        }
    }

    /**
     * observe a histogram value (e.g. duration in seconds)
     * @param string $name
     * @param array $labels
     * @param float $value
     * @param array $buckets ascending bucket upper bounds
     */
    public static function histogram(string $name, array $labels, float $value, array $buckets = self::BUCKETS_HTTP) : void {
        try {
            if($redis = self::getRedis()){
                $le = '+Inf';
                foreach($buckets as $bucket){
                    if($value <= $bucket){
                        $le = self::formatFloat($bucket);
                        break;
                    }
                }
                $labelStr = self::labelString($labels);
                $pipe = $redis->multi(\Redis::PIPELINE);
                $pipe->hIncrBy(self::REDIS_KEY, 'hb|' . $name . '|' . $le . '|' . $labelStr, 1);
                $pipe->hIncrByFloat(self::REDIS_KEY, 'hs|' . $name . '|' . $labelStr, $value);
                $pipe->hIncrBy(self::REDIS_KEY, 'hc|' . $name . '|' . $labelStr, 1);
                $pipe->exec();
            }
        } catch (\Throwable $e) {
            // metrics must never break the request
        }
    }

    /**
     * render all stored samples in Prometheus text exposition format
     * @return string
     */
    public static function render() : string {
        $out = '';
        try {
            if(!$redis = self::getRedis()){
                return "# Redis unavailable, no app metrics\n";
            }
            $fields = $redis->hGetAll(self::REDIS_KEY);
            if(!is_array($fields)){
                return '';
            }

            $counters = [];     // name => [labelStr => value]
            $gauges = [];       // name => [labelStr => value]
            $histograms = [];   // name => [labelStr => ['buckets' => [le => n], 'sum' => x, 'count' => n]]

            foreach($fields as $field => $value){
                $parts = explode('|', $field);
                $type = array_shift($parts);
                switch($type){
                    case 'c':
                        list($name, $labelStr) = $parts + [null, ''];
                        $counters[$name][$labelStr] = $value;
                        break;
                    case 'g':
                        list($name, $labelStr) = $parts + [null, ''];
                        $gauges[$name][$labelStr] = $value;
                        break;
                    case 'hb':
                        list($name, $le, $labelStr) = $parts + [null, null, ''];
                        $histograms[$name][$labelStr]['buckets'][$le] = (int)$value;
                        break;
                    case 'hs':
                        list($name, $labelStr) = $parts + [null, ''];
                        $histograms[$name][$labelStr]['sum'] = (float)$value;
                        break;
                    case 'hc':
                        list($name, $labelStr) = $parts + [null, ''];
                        $histograms[$name][$labelStr]['count'] = (int)$value;
                        break;
                }
            }

            foreach($counters as $name => $series){
                $out .= '# TYPE ' . $name . " counter\n";
                foreach($series as $labelStr => $value){
                    $out .= $name . self::wrapLabels($labelStr) . ' ' . $value . "\n";
                }
            }
            foreach($gauges as $name => $series){
                $out .= '# TYPE ' . $name . " gauge\n";
                foreach($series as $labelStr => $value){
                    $out .= $name . self::wrapLabels($labelStr) . ' ' . $value . "\n";
                }
            }
            foreach($histograms as $name => $series){
                $out .= '# TYPE ' . $name . " histogram\n";
                foreach($series as $labelStr => $data){
                    $buckets = isset($data['buckets']) ? $data['buckets'] : [];
                    $count = isset($data['count']) ? $data['count'] : 0;
                    $sum = isset($data['sum']) ? $data['sum'] : 0;
                    // sort by upper bound, '+Inf' last
                    uksort($buckets, function($a, $b){
                        if($a === '+Inf') return 1;
                        if($b === '+Inf') return -1;
                        return (float)$a <=> (float)$b;
                    });
                    $cumulative = 0;
                    foreach($buckets as $le => $n){
                        if($le === '+Inf'){
                            continue; // rendered below from total count
                        }
                        $cumulative += $n;
                        $out .= $name . '_bucket' . self::wrapLabels($labelStr, ['le' => $le]) . ' ' . $cumulative . "\n";
                    }
                    $out .= $name . '_bucket' . self::wrapLabels($labelStr, ['le' => '+Inf']) . ' ' . $count . "\n";
                    $out .= $name . '_sum' . self::wrapLabels($labelStr) . ' ' . $sum . "\n";
                    $out .= $name . '_count' . self::wrapLabels($labelStr) . ' ' . $count . "\n";
                }
            }
        } catch (\Throwable $e) {
            $out .= "# render error: " . str_replace("\n", ' ', $e->getMessage()) . "\n";
        }
        return $out;
    }

    /**
     * build canonical label string: sorted keys, sanitized values
     * @param array $labels
     * @return string
     */
    protected static function labelString(array $labels) : string {
        if(!$labels){
            return '';
        }
        ksort($labels);
        $parts = [];
        foreach($labels as $key => $value){
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key);
            // '|' is the field separator, '"' would break exposition format
            $value = str_replace(['|', '"', "\n", '\\'], '_', (string)$value);
            $parts[] = $key . '="' . $value . '"';
        }
        return implode(',', $parts);
    }

    /**
     * wrap stored label string (+ optional extra labels) in braces for output
     * @param string $labelStr
     * @param array $extra
     * @return string
     */
    protected static function wrapLabels(string $labelStr, array $extra = []) : string {
        $parts = [];
        if($labelStr !== ''){
            $parts[] = $labelStr;
        }
        foreach($extra as $key => $value){
            $parts[] = $key . '="' . $value . '"';
        }
        return $parts ? '{' . implode(',', $parts) . '}' : '';
    }

    /**
     * @param float $value
     * @return string
     */
    protected static function formatFloat(float $value) : string {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
