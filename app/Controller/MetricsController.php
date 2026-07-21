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

use Exodus4D\Pathfinder\Lib\CgroupMemory;
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
        echo $this->opcacheMetrics();
        echo $this->cgroupMemoryMetrics();

        // 실패 지표는 반드시 마지막에 한 번만 (TYPE 라인 중복 → 스크레이프 전체 거부 방지)
        echo $this->renderScrapeErrors();
    }

    /**
     * cgroup 메모리 → pf_memory_* metrics (이 컨테이너의 실사용/워킹셋/한도)
     *
     * opcache 메모리(pf_opcache_memory_*)는 컴파일 캐시 한 조각일 뿐이라
     * 컨테이너가 실제로 얼마나 쓰는지는 지금까지 어떤 지표로도 볼 수 없었다.
     * 백프레셔의 메모리 신호가 v1/v2 경로 문제로 죽어 있던 것도
     * 이 지표가 없어서 아무도 몰랐다.
     *
     * (Alloy 를 켜면 cadvisor 가 전 컨테이너에 대해 비슷한 계열을 내보낸다.
     *  다만 Alloy 는 아직 미기동이고, 백프레셔가 소비할 값은 어차피 앱 안에서
     *  읽어야 하므로 이 지표는 그 값과 같은 출처라는 점에서 의미가 있다.)
     *
     * @return string
     */
    protected function cgroupMemoryMetrics() : string {
        $usage = CgroupMemory::readUsageBytes();
        if(is_null($usage)){
            // 측정 불가를 0으로 내보내면 '메모리가 한가함'과 구분되지 않는다.
            // 값을 내지 않고 scrape 에러로 드러낸다.
            return $this->scrapeError('cgroup_memory');
        }

        $out = "# TYPE pf_memory_usage_bytes gauge\n";
        $out .= 'pf_memory_usage_bytes ' . $usage . "\n";

        // 압력 판단의 기준은 usage 가 아니라 워킹셋이다 (usage 는 회수 가능한
        // page cache 를 포함해 가동시간에 비례해 한도 쪽으로 올라가 머문다).
        $workingSet = CgroupMemory::readWorkingSetBytes();
        if(!is_null($workingSet)){
            $out .= "# TYPE pf_memory_working_set_bytes gauge\n";
            $out .= 'pf_memory_working_set_bytes ' . $workingSet . "\n";
        }

        // 한도는 미설정(무제한)일 수 있다 — 그건 에러가 아니므로 지표만 생략한다
        $limit = CgroupMemory::readLimitBytes();
        if(!is_null($limit)){
            $out .= "# TYPE pf_memory_limit_bytes gauge\n";
            $out .= 'pf_memory_limit_bytes ' . $limit . "\n";
        }

        return $out;
    }

    /**
     * Zend OPcache 상태 → pf_opcache_* metrics (히트율/메모리 — 컴파일 병목 감시)
     * @return string
     */
    protected function opcacheMetrics() : string {
        if(!function_exists('opcache_get_status')){
            return $this->scrapeError('opcache');
        }
        $status = @opcache_get_status(false);
        if(!is_array($status)){
            return $this->scrapeError('opcache');
        }

        $out = "# TYPE pf_opcache_enabled gauge\n";
        $out .= 'pf_opcache_enabled ' . (int)($status['opcache_enabled'] ?? 0) . "\n";
        if(isset($status['opcache_statistics'])){
            $stats = $status['opcache_statistics'];
            $out .= "# TYPE pf_opcache_hits_total counter\n";
            $out .= 'pf_opcache_hits_total ' . (int)$stats['hits'] . "\n";
            $out .= "# TYPE pf_opcache_misses_total counter\n";
            $out .= 'pf_opcache_misses_total ' . (int)$stats['misses'] . "\n";
            $out .= "# TYPE pf_opcache_cached_scripts gauge\n";
            $out .= 'pf_opcache_cached_scripts ' . (int)$stats['num_cached_scripts'] . "\n";
        }
        if(isset($status['memory_usage'])){
            $out .= "# TYPE pf_opcache_memory_used_bytes gauge\n";
            $out .= 'pf_opcache_memory_used_bytes ' . (int)$status['memory_usage']['used_memory'] . "\n";
            $out .= "# TYPE pf_opcache_memory_free_bytes gauge\n";
            $out .= 'pf_opcache_memory_free_bytes ' . (int)$status['memory_usage']['free_memory'] . "\n";
        }
        return $out;
    }

    /**
     * gauges maintained elsewhere in plain Redis keys (backpressure layer)
     * @return string
     */
    protected function runtimeGauges() : string {
        $out = '';
        try {
            if($redis = $this->getRedis()){
                // (pf_active_workers 게이지 폐기 — 드리프트. pf_phpfpm_active_processes 사용)
                $pressure = $redis->get('PF_P_SKIP');
                if($pressure !== false){
                    $out .= "# TYPE pf_backpressure_score gauge\n";
                    $out .= 'pf_backpressure_score ' . (float)$pressure . "\n";
                }

                // 데몬이 압력 계산에 실제로 쓰는 메모리 신호.
                //
                // 위 pf_memory_* 는 '이 컨테이너가 지금 얼마나 쓰나'이고, 이건
                // '데몬이 그걸 넘겨받았나'다. 웹→Redis→데몬 파이프라인이 끊기면
                // (게시 경로 미실행, TTL 만료, Redis 장애) 이 시계열이 사라지므로,
                // 신호가 조용히 죽는 것을 스크레이프 에러 없이도 알아챌 수 있다.
                $webMemory = $redis->hGetAll('PF_WEB_MEM');
                if(is_array($webMemory) && isset($webMemory['workingSet'], $webMemory['limit'])){
                    $ratio = CgroupMemory::pressureRatio(
                        (int)$webMemory['workingSet'],
                        (int)$webMemory['limit']
                    );
                    if(!is_null($ratio)){
                        $out .= "# TYPE pf_backpressure_memory_ratio gauge\n";
                        $out .= 'pf_backpressure_memory_ratio ' . round($ratio, 4) . "\n";
                    }
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
     * 실패한 스크레이프 대상을 모아둔다 (렌더는 renderScrapeErrors()에서 한 번에).
     * @var array
     */
    protected $scrapeErrors = [];

    /**
     * 스크레이프 실패를 기록한다. 빈 문자열을 돌려주므로 호출부는 그대로 return 하면 된다.
     *
     * 예전에는 호출될 때마다 '# TYPE pf_exporter_scrape_error gauge' 를 직접 찍었다.
     * 한 스크레이프에서 두 대상이 동시에 실패하면 같은 metric family 에 TYPE 라인이
     * 두 번 나가고, Prometheus 텍스트 파서는 그걸 'second TYPE line' 으로 보고
     * **응답 전체를 거부**한다 → 실패한 지표만 빠지는 게 아니라 그 스크레이프의
     * 모든 pf_* 지표가 통째로 사라진다.
     *
     * @param string $target
     * @return string
     */
    protected function scrapeError(string $target) : string {
        $this->scrapeErrors[] = $target;
        return '';
    }

    /**
     * 모아둔 스크레이프 실패를 TYPE 라인 하나로 렌더한다.
     * @return string
     */
    protected function renderScrapeErrors() : string {
        if(empty($this->scrapeErrors)){
            return '';
        }
        $out = "# TYPE pf_exporter_scrape_error gauge\n";
        foreach(array_unique($this->scrapeErrors) as $target){
            $out .= 'pf_exporter_scrape_error{target="' . $target . '"} 1' . "\n";
        }
        return $out;
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
