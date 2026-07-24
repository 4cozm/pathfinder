<?php

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\CgroupMemory;
use Exodus4D\Pathfinder\Lib\Config;

class BackpressureManager extends \Prefab {

    const KEY_P_SKIP                = 'PF_P_SKIP';
    const KEY_ACTIVE_WORKERS        = 'PF_ACTIVE_WORKERS';
    const KEY_ESI_METRICS_PREFIX    = 'PF_ESI_METRICS_';

    /**
     * 웹(pf) 컨테이너가 게시한 자기 메모리 상태. 데몬이 읽어서 압력 점수에 쓴다.
     *
     * 왜 Redis 를 경유하는가:
     *  updatePressureScore()는 pf-daemon 컨테이너에서 돈다. 거기서 cgroup 을 직접 읽으면
     *  **데몬 자신의** 메모리(mem_limit 300MB)를 읽게 되는데, 정작 조절하려는 대상은
     *  웹 워커의 ESI 폴링이다. 즉 분모(한도)도 분자(사용량)도 엉뚱한 컨테이너 것이 된다.
     *  getActiveWorkers()가 http://pf:8081/fpm-status 로 웹 컨테이너를 건너가 읽는 것과
     *  같은 이유이며, 메모리는 Redis 를 전송로로 쓴다.
     */
    const KEY_WEB_MEMORY            = 'PF_WEB_MEM';

    /**
     * 측정 주기 election 용 키. 웹 워커가 동시에 여러 개 떠 있어도
     * 이 창 안에서는 한 요청만 실제 cgroup 을 읽는다.
     */
    const KEY_WEB_MEMORY_GATE       = 'PF_WEB_MEM_GATE';

    /**
     * 측정 주기(초). 데몬 tick(기본 10s)보다 촘촘하면 충분하다.
     */
    const WEB_MEMORY_SAMPLE_SECONDS = 5;

    /**
     * 게시값 수명(초). 웹이 죽거나 게시 경로가 끊기면 이 시간 뒤 신호가 사라진다.
     *
     * TTL 이 없으면 마지막 값이 영원히 남아 실제와 무관한 압력을 만든다.
     * PF_ACTIVE_WORKERS 가 정확히 그렇게 드리프트해 상시 스로틀을 유발했다.
     */
    const WEB_MEMORY_TTL_SECONDS    = 30;

    const WEIGHT_FAIL               = 40;   // Error rate weight
    const WEIGHT_MEMORY             = 30;   // Memory pressure weight
    const WEIGHT_CONCURRENCY        = 20;   // Worker load weight
    const WEIGHT_LATENCY            = 10;   // Latency spike weight

    /**
     * 메모리 신호를 점수에 반영할지 여부.
     *
     * 2026-07-24 활성화. 켜기 전 조건이었던 "하루 관측 후 MEM_PRESSURE_FLOOR 실측 조정"을
     * 3일치(7/22~7/24, 재시작 주기 한 바퀴 이상)로 마쳤고, 결론은 **FLOOR 조정 불필요**다.
     *
     *   pf_backpressure_memory_ratio  7/22 max 0.23 / 7/23 max 0.29 / 7/24 p99 0.26 max 0.31
     *   working set 절대값            p50 181MB / p99 312MB / max 351MB (한도 1200MB)
     *
     * 즉 실측 최대치(0.31)가 FLOOR(0.7)의 절반도 안 되므로, 켜도 현재 부하에서는
     * s_mem 이 계속 0 이고 점수는 그대로다. 켜는 것의 의미는 "지금 뭔가를 바꾼다"가
     * 아니라 **working set 이 840MB(=0.7×1200MB)를 넘는 이상 상황에서만 발동하는
     * 안전망을 살려두는 것**이다. 우려했던 "켜는 순간 점수가 전 구간에서 올라감"은
     * 분자를 usage → working set(usage - inactive_file)으로 바꾼 뒤 해소됐다.
     * (page cache 가 섞인 usage 는 가동시간에 비례해 한도까지 올라가 머물렀다)
     *
     * 회귀 시 이 상수만 false 로 되돌리면 된다.
     */
    const MEMORY_PRESSURE_ENABLED   = true;

    /**
     * 메모리 사용률이 이 값을 넘어야 압력으로 계산한다.
     * 넘은 만큼을 (1.0 - FLOOR) 구간에 정규화해 0.0~1.0 신호로 만든다.
     *
     * 0.7 = working set 840MB / 1200MB. 실측 max 가 0.31(351MB)이라 평상시엔 절대
     * 닿지 않는다. 이 값을 실측(0.31) 근처로 낮추면 정상 부하가 압력으로 잡혀
     * 폴링이 조기 스로틀되므로(PF_ACTIVE_WORKERS 드리프트 사고와 같은 유형) 낮추지 말 것.
     */
    const MEM_PRESSURE_FLOOR        = 0.7;
    // pm.max_children(32)과 일치시킴 — static/php/fpm-pool.conf 와 반드시 같이 움직인다.
    // 이 값은 워커 압력의 분모라, max_children 만 올리고 여기를 두면 실제로는 62% 여유가
    // 있는데 분모를 다 채운 것으로 읽어 폴링을 조기 스로틀한다. 구 값 12가 2GB/워커10
    // 시절 기준으로 남아 건강한 폴링 버스트(워커 6+)에도 압력을 붙이던 것과 같은 사고다.
    // (50% 바닥과 결합해 이제 워커 16+부터 압력으로 계산됨)
    const WORKER_LIMIT              = 32;

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
     * [웹 컨테이너에서 호출] 자기 cgroup 메모리 상태를 Redis 에 게시한다.
     *
     * 요청 UNLOAD 훅에서 불린다. 매 요청 cgroup 을 읽지 않도록
     * SET NX EX 로 5초에 한 요청만 실제 측정하게 한다 —
     * 흔한 경로(이미 최근에 측정됨)의 비용은 Redis 왕복 1회다.
     */
    public function publishWebMemory() : void {
        try {
            if(!$redis = $this->getRedis()) return;

            // 이 창의 측정 담당을 한 요청으로 정한다
            if(!$redis->set(self::KEY_WEB_MEMORY_GATE, 1, ['nx', 'ex' => self::WEB_MEMORY_SAMPLE_SECONDS])){
                return;
            }

            $workingSet = CgroupMemory::readWorkingSetBytes();
            $limit      = CgroupMemory::readLimitBytes();
            if(is_null($workingSet) || is_null($limit)){
                // 측정 불가는 게시하지 않는다 → TTL 만료로 '신호 없음'이 된다.
                // 0 같은 중립값을 써넣으면 '한가함'과 구분되지 않는다.
                return;
            }

            $redis->hMset(self::KEY_WEB_MEMORY, [
                'workingSet' => $workingSet,
                'limit'      => $limit,
            ]);
            $redis->expire(self::KEY_WEB_MEMORY, self::WEB_MEMORY_TTL_SECONDS);
        } catch (\Throwable $e) {
            // 관측이 요청을 깨뜨려선 안 된다
        }
    }

    /**
     * [데몬에서 호출] 웹 컨테이너의 메모리 사용률 (0.0~1.0+). 신호 없음/측정 불가 시 null.
     *
     * 값의 출처가 로컬 cgroup 이 아니라 Redis 인 이유는 KEY_WEB_MEMORY 주석 참고.
     *
     * @return float|null
     */
    protected function getMemoryPressureRatio() : ?float {
        try {
            if(!$redis = $this->getRedis()) return null;

            $data = $redis->hGetAll(self::KEY_WEB_MEMORY);
            if(!is_array($data) || !isset($data['workingSet'], $data['limit'])){
                return null;
            }

            return CgroupMemory::pressureRatio((int)$data['workingSet'], (int)$data['limit']);
        } catch (\Throwable $e) {
            return null;
        }
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
        
        // 측정 불가(null)는 0(=한가함)과 같이 취급된다. 신호가 없을 뿐 압력의 근거는 아니므로
        // 이게 맞는 선택이지만, 그래서 '측정이 죽은 것'이 점수만 봐서는 안 보인다.
        // 측정 실패 자체는 /metrics 의 pf_exporter_scrape_error{target="cgroup_memory"} 로 드러난다.
        $s_mem = 0;
        if(self::MEMORY_PRESSURE_ENABLED){
            $memRatio = $this->getMemoryPressureRatio();
            if(!is_null($memRatio) && $memRatio > self::MEM_PRESSURE_FLOOR){
                $headroom = 1.0 - self::MEM_PRESSURE_FLOOR;
                $s_mem = min(1.0, ($memRatio - self::MEM_PRESSURE_FLOOR) / $headroom);
            }
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
