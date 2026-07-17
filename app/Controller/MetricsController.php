<?php
/**
 * Prometheus /metrics endpoint.
 *
 * 의도적으로 Controller를 상속하지 않는다:
 *  - beforeroute()의 세션 초기화(= sessions 테이블 row lock)와
 *    PF_ACTIVE_WORKERS 증가가 스크레이프마다 발생하는 것을 방지.
 *
 * 노출 범위: nginx 내부 전용 포트(8081)에서만 접근 가능해야 한다.
 * (:80/:443 server block에서는 /metrics를 deny — static/nginx/site.conf 참고)
 *
 * 자체 앱 메트릭(Redis PF_METRICS) 외에 같은 컨테이너의
 * php-fpm status(/fpm-status?json)와 nginx stub_status(/nginx_status)를
 * 함께 수집해 단일 엔드포인트로 통합한다 → 별도 exporter 컨테이너 불필요.
 */

namespace Exodus4D\Pathfinder\Controller;

use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Lib\Metrics;

class MetricsController {

    /**
     * timeout (seconds) for local status page sub-requests
     */
    const STATUS_FETCH_TIMEOUT = 1.0;

    /**
     * @param \Base $f3
     */
    public function init(\Base $f3){
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

        echo Metrics::render();
        echo $this->runtimeGauges();
        echo $this->phpFpmMetrics();
        echo $this->nginxStubMetrics();
    }

    /**
     * gauges maintained elsewhere in plain Redis keys (backpressure layer)
     * @return string
     */
    protected function runtimeGauges() : string {
        $out = '';
        try {
            if($redis = $this->getRedis()){
                $workers = $redis->get('PF_ACTIVE_WORKERS');
                if($workers !== false){
                    $out .= "# TYPE pf_active_workers gauge\n";
                    $out .= 'pf_active_workers ' . (int)$workers . "\n";
                }
                $pressure = $redis->get('PF_P_SKIP');
                if($pressure !== false){
                    $out .= "# TYPE pf_backpressure_score gauge\n";
                    $out .= 'pf_backpressure_score ' . (float)$pressure . "\n";
                }
            }
        } catch (\Throwable $e) {
            $out .= $this->scrapeError('runtime_gauges');
        }
        return $out;
    }

    /**
     * php-fpm pool status → pf_phpfpm_* metrics
     * (pm.status_path = /fpm-status, exposed on the internal :8081 vhost)
     * @return string
     */
    protected function phpFpmMetrics() : string {
        $json = $this->fetchLocal('/fpm-status?json');
        if($json === null){
            return $this->scrapeError('phpfpm');
        }
        $status = json_decode($json, true);
        if(!is_array($status)){
            return $this->scrapeError('phpfpm');
        }

        $map = [
            // fpm status field            metric suffix                 type
            'accepted conn'         => ['accepted_connections_total',   'counter'],
            'listen queue'          => ['listen_queue',                 'gauge'],
            'max listen queue'      => ['listen_queue_max',             'gauge'],
            'listen queue len'      => ['listen_queue_length',          'gauge'],
            'idle processes'        => ['idle_processes',               'gauge'],
            'active processes'      => ['active_processes',             'gauge'],
            'total processes'       => ['total_processes',              'gauge'],
            'max active processes'  => ['max_active_processes',         'gauge'],
            'max children reached'  => ['max_children_reached_total',   'counter'],
            'slow requests'         => ['slow_requests_total',          'counter'],
        ];

        $out = '';
        foreach($map as $field => list($suffix, $type)){
            if(isset($status[$field])){
                $name = 'pf_phpfpm_' . $suffix;
                $out .= '# TYPE ' . $name . ' ' . $type . "\n";
                $out .= $name . ' ' . (int)$status[$field] . "\n";
            }
        }
        return $out;
    }

    /**
     * nginx stub_status → pf_nginx_* metrics
     * @return string
     */
    protected function nginxStubMetrics() : string {
        $text = $this->fetchLocal('/nginx_status');
        if($text === null){
            return $this->scrapeError('nginx');
        }

        $out = '';
        if(preg_match('/Active connections:\s+(\d+)/', $text, $m)){
            $out .= "# TYPE pf_nginx_connections_active gauge\n";
            $out .= 'pf_nginx_connections_active ' . $m[1] . "\n";
        }
        if(preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/m', $text, $m)){
            $out .= "# TYPE pf_nginx_connections_accepted_total counter\n";
            $out .= 'pf_nginx_connections_accepted_total ' . $m[1] . "\n";
            $out .= "# TYPE pf_nginx_connections_handled_total counter\n";
            $out .= 'pf_nginx_connections_handled_total ' . $m[2] . "\n";
            $out .= "# TYPE pf_nginx_http_requests_total counter\n";
            $out .= 'pf_nginx_http_requests_total ' . $m[3] . "\n";
        }
        if(preg_match('/Reading:\s+(\d+)\s+Writing:\s+(\d+)\s+Waiting:\s+(\d+)/', $text, $m)){
            $out .= "# TYPE pf_nginx_connections_reading gauge\n";
            $out .= 'pf_nginx_connections_reading ' . $m[1] . "\n";
            $out .= "# TYPE pf_nginx_connections_writing gauge\n";
            $out .= 'pf_nginx_connections_writing ' . $m[2] . "\n";
            $out .= "# TYPE pf_nginx_connections_waiting gauge\n";
            $out .= 'pf_nginx_connections_waiting ' . $m[3] . "\n";
        }
        return $out;
    }

    /**
     * fetch a status page from the local internal vhost
     * @param string $path
     * @return string|null
     */
    protected function fetchLocal(string $path) : ?string {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::STATUS_FETCH_TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents('http://127.0.0.1:8081' . $path, false, $context);
            return ($body === false) ? null : $body;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string $target
     * @return string
     */
    protected function scrapeError(string $target) : string {
        return "# TYPE pf_exporter_scrape_error gauge\n"
            . 'pf_exporter_scrape_error{target="' . $target . '"} 1' . "\n";
    }

    /**
     * @return \Redis|null
     */
    protected function getRedis() : ?\Redis {
        static $redis = null;
        if(is_null($redis) && extension_loaded('redis')){
            $redis = new \Redis();
            try {
                if(!$redis->pconnect(
                    Config::getEnvironmentData('REDIS_HOST'),
                    Config::getEnvironmentData('REDIS_PORT') ? : 6379,
                    Config::REDIS_OPT_TIMEOUT
                )){
                    $redis = null;
                } else if($auth = Config::getEnvironmentData('REDIS_AUTH')){
                    $redis->auth($auth);
                }
            } catch (\Exception $e) {
                $redis = null;
            }
        }
        return $redis;
    }
}
