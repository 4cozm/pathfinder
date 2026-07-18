<?php

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\Config;

class BackpressureManager extends \Prefab {

    const KEY_P_SKIP                = 'PF_P_SKIP';
    const KEY_ACTIVE_WORKERS        = 'PF_ACTIVE_WORKERS';
    const KEY_ESI_METRICS_PREFIX    = 'PF_ESI_METRICS_';
    
    const WEIGHT_FAIL               = 40;   // Error rate weight
    const WEIGHT_MEMORY             = 30;   // Memory pressure weight
    const WEIGHT_CONCURRENCY        = 20;   // Worker load weight
    const WEIGHT_LATENCY            = 10;   // Latency spike weight

    const MEM_LIMIT_BYTES           = 400 * 1024 * 1024; // 400MB
    // pm.max_children(20)과 일치시킴. 구 값 12는 2GB/워커10 시절 기준이라
    // 건강한 폴링 버스트(워커 6+)에도 압력이 붙어 상시 스로틀 → 트래킹 블라인드 스팟의
    // 만성 원인이었다 (50% 바닥과 결합해 이제 워커 10+부터 압력으로 계산됨)
    const WORKER_LIMIT              = 20;

    // php-fpm status 페이지 (내부 전용 :8081 vhost, docker 네트워크에서 서비스명 'pf')
    const FPM_STATUS_URL            = 'http://pf:8081/fpm-status?json';

    /**
     * @var \Redis|null
     */
    protected $redis = null;

    /**
     * get Redis instance
     * @return \Redis|null
     */
    protected function getRedis() : ?\Redis {
        if(is_null($this->redis) && extension_loaded('redis')){
            $this->redis = new \Redis();
            try {
                if(!$this->redis->pconnect(
                    Config::getEnvironmentData('REDIS_HOST'),
                    Config::getEnvironmentData('REDIS_PORT') ? : 6379,
                    Config::REDIS_OPT_TIMEOUT
                )){
                    $this->redis = null;
                } else if($auth = Config::getEnvironmentData('REDIS_AUTH')){
                    $this->redis->auth($auth);
                }
            } catch (\Exception $e) {
                $this->redis = null;
            }
        }
        return $this->redis;
    }

    /**
     * Get current container memory usage (RSS)
     * @return int bytes
     */
    protected function getMemoryUsage() : int {
        $usage = 0;
        $path = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
        if(file_exists($path)){
            $usage = (int)trim(file_get_contents($path));
        }
        return $usage;
    }

    /**
     * Get current busy php-fpm worker count from the fpm status page (ground truth).
     *
     * 기존 Redis PF_ACTIVE_WORKERS(incr/decr) 방식은 unload가 실행되지 않는 경로
     * (request_terminate_timeout 강제종료, fatal 등)에서 위로만 드리프트해
     * 실측 32+ (max_children=20 초과!)까지 오염됐고, 그 결과 압력 점수가 상시
     * 18~20 → 매분 캐릭터 ~20% 폴링 스로틀 → 트래킹 끊김을 유발했다. 폐기.
     * @return int
     */
    protected function getActiveWorkers() : int {
        try {
            $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
            $body = @file_get_contents(self::FPM_STATUS_URL, false, $context);
            if($body !== false){
                $status = json_decode($body, true);
                if(is_array($status) && isset($status['active processes'])){
                    return (int)$status['active processes'];
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        // 상태 페이지 접근 불가 → 신호 없음(압력 0)으로 처리. 오탐 스로틀보다 낫다
        return 0;
    }

    /**
     * Update the global pressure score based on fused signals
     * This should be called by a background process or periodically by a worker
     */
    public function updatePressureScore() : void {
        if(!$redis = $this->getRedis()) return;

        $now = time();
        $activeWorkers = $this->getActiveWorkers();
        $memUsage = $this->getMemoryUsage();

        // 1. Gather ESI metrics from last 3 buckets (15 seconds)
        $metrics = ['total' => 0, 'fail' => 0, 'slow' => 0, 'latency_sum' => 0];
        $currentBucket = floor($now / 5);
        for($i = 0; $i < 3; $i++){
            $b = $redis->hGetAll(self::KEY_ESI_METRICS_PREFIX . ($currentBucket - $i));
            if($b){
                $metrics['total']       += (int)($b['total'] ?? 0);
                $metrics['fail']        += (int)($b['fail'] ?? 0);
                $metrics['slow']        += (int)($b['slow'] ?? 0);
                $metrics['latency_sum'] += (int)($b['latency_sum'] ?? 0);
            }
        }

        // 2. Calculate normalized signals (0.0 to 1.0)
        $s_fail = ($metrics['total'] > 5) ? ($metrics['fail'] / $metrics['total']) : 0;
        
        $s_mem = 0;
        if(self::MEM_LIMIT_BYTES > 0){
            $memRatio = $memUsage / self::MEM_LIMIT_BYTES;
            $s_mem = ($memRatio > 0.7) ? min(1.0, ($memRatio - 0.7) / 0.3) : 0;
        }

        $s_work = min(1.0, $activeWorkers / self::WORKER_LIMIT);
        if($s_work < 0.5) $s_work = 0; // Only count as pressure if over 50% capacity

        $avgLatency = ($metrics['total'] > 5) ? ($metrics['latency_sum'] / $metrics['total']) : 0;
        $s_lat = ($avgLatency > 2000) ? min(1.0, ($avgLatency - 2000) / 3000) : 0;

        // 3. Signal Fusion
        $calculatedScore = ($s_fail * self::WEIGHT_FAIL) + 
                           ($s_mem * self::WEIGHT_MEMORY) + 
                           ($s_work * self::WEIGHT_CONCURRENCY) + 
                           ($s_lat * self::WEIGHT_LATENCY);

        // 4. Fast-Attack, Exponential Decay (Hysteresis)
        $currentScore = (float)$redis->get(self::KEY_P_SKIP);
        
        if($calculatedScore > $currentScore){
            // Fast attack: jump to new high
            $newScore = $calculatedScore;
        } else {
            // Exponential decay: reduce by 10%
            $newScore = $currentScore * 0.9;
            if($newScore < 5) $newScore = 0; // Threshold to turn off
        }

        $redis->set(self::KEY_P_SKIP, round($newScore, 2));
    }

    /**
     * Check if a specific request should be throttled using deterministic sampling
     * @param int|null $characterId Use characterId for fairness, or random for guest
     * @return bool
     */
    public function shouldThrottle(?int $characterId = null) : bool {
        if(!$redis = $this->getRedis()) return false;

        $p_skip = (float)$redis->get(self::KEY_P_SKIP);
        if($p_skip <= 0) return false;

        // Deterministic sampling: 같은 유저는 같은 요청에 대해 동일하게 처리됨 (60초 주기)
        $seed = $characterId ?: mt_rand();
        $window = floor(time() / 60);
        $hash = crc32($seed . $window) % 100;

        return ($hash < $p_skip);
    }
}
