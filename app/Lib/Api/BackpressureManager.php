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
    const WORKER_LIMIT              = 12;

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
     * Update the global pressure score based on fused signals
     * This should be called by a background process or periodically by a worker
     */
    public function updatePressureScore() : void {
        if(!$redis = $this->getRedis()) return;

        $now = time();
        $activeWorkers = (int)$redis->get(self::KEY_ACTIVE_WORKERS);
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
