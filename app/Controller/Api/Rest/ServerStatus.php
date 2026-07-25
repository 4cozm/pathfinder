<?php
/**
 * 서버 상태 요약 — 헤더 인디케이터가 클릭 시 lazy 하게 부른다.
 *
 * 이 응답은 "보고에 붙일 스냅샷"으로도 쓰인다. 그래서 측정 시각(measuredAt)을
 * 페이로드 안에 반드시 박아 넣는다. 캐시 때문에 응답 시각과 측정 시각이 최대
 * CACHE_TTL 초 다르고, 그 값 없이 스크린샷을 찍으면 나중에 언제 것인지 알 수 없다.
 *
 * /metrics 를 그대로 노출하지 않는 이유:
 *  Metrics::render() 는 Redis 해시(PF_METRICS)를 통째로 순회해 자동 노출한다.
 *  그걸 화면에 연결하면 앞으로 추가하는 모든 내부 계측(예: 디버깅용 레이스 카운터)이
 *  자동으로 유저 화면에 나타난다. 여기서는 필드를 명시적으로 골라, 내부 계측을
 *  마음껏 추가해도 UI 가 흔들리지 않게 한다. 비밀 유지가 아니라 유지보수 문제다.
 */

namespace Exodus4D\Pathfinder\Controller\Api\Rest;

use Exodus4D\Pathfinder\Lib\Api\BackpressureManager;
use Exodus4D\Pathfinder\Lib\CgroupMemory;
use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Lib\HostLoad;
use Exodus4D\Pathfinder\Lib\LocalStatus;

class ServerStatus extends AbstractRestController {

    /**
     * 응답 캐시 수명(초).
     *
     * 캐싱을 routes.ini 의 라우트 TTL 이 아니라 여기서 하는 이유:
     *  1) F3 라우트 캐시는 핸들러 자체를 건너뛰므로 인증(AccessController)도 건너뛴다
     *  2) measuredAt 을 페이로드 안에 같이 캐시해야 "언제 잰 값인지"가 보존된다
     */
    const CACHE_TTL = 30;

    const CACHE_KEY = 'PF_SERVER_STATUS';

    /**
     * 신호등 임계값 — backpressure score 기준.
     * 새 임계값을 발명하지 않고 이미 실전 검증된 복합 점수를 재사용한다.
     * (실패율 40 + 메모리 30 + 동시성 20 + 지연 10 가중합, 히스테리시스 포함)
     */
    const SCORE_WARN     = 10;
    const SCORE_DEGRADED = 30;

    /**
     * GET /api/rest/ServerStatus
     * @param \Base $f3
     */
    public function get(\Base $f3){
        $this->out($this->getStatus());
    }

    /**
     * 캐시된 스냅샷을 돌려주고, 없으면 새로 만든다.
     * @return array
     */
    protected function getStatus() : array {
        $redis = $this->getRedis();

        if($redis){
            $cached = $redis->get(self::CACHE_KEY);
            if(is_string($cached) && $cached !== ''){
                $decoded = json_decode($cached, true);
                if(is_array($decoded)){
                    return $decoded;
                }
            }
        }

        $status = $this->buildStatus($redis);

        if($redis){
            // 캐시 실패는 조용히 넘긴다 — 상태 조회가 캐시 때문에 실패하면 본말전도다
            try {
                $redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($status));
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $status;
    }

    /**
     * @param \Redis|null $redis
     * @return array
     */
    protected function buildStatus(?\Redis $redis) : array {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $kst = (clone $now)->setTimezone(new \DateTimeZone('Asia/Seoul'));

        $score  = $this->readScore($redis);
        $worker = $this->workerStatus();
        $memory = $this->memoryStatus();
        $cpu    = $this->cpuStatus();

        return [
            'measuredAt' => [
                'utc'   => $now->format('Y-m-d H:i:s') . ' UTC',
                'kst'   => $kst->format('Y-m-d H:i:s') . ' KST',
                'epoch' => (int)$now->format('U'),
            ],
            // 응답이 최대 이만큼 낡았을 수 있다는 뜻. 화면에 같이 보여줄 것.
            'cacheTtl'   => self::CACHE_TTL,
            'level'      => $this->level($score, $worker),
            'score'      => $score,
            'worker'     => $worker,
            'memory'     => $memory,
            'cpu'        => $cpu,
        ];
    }

    /**
     * 신호등 색. score 가 주 신호이고, 큐가 쌓인 순간은 점수가 따라오기 전에
     * 이미 체감 지연이므로 별도로 승격시킨다.
     * @param float|null $score
     * @param array $worker
     * @return string ok|warn|degraded|unknown
     */
    protected function level(?float $score, array $worker) : string {
        if(is_null($score)){
            return 'unknown';
        }
        if($score >= self::SCORE_DEGRADED){
            return 'degraded';
        }
        if($score >= self::SCORE_WARN || (int)($worker['queue'] ?? 0) > 0){
            return 'warn';
        }

        return 'ok';
    }

    /**
     * @param \Redis|null $redis
     * @return float|null 측정 불가 시 null (0 과 구분해야 한다)
     */
    protected function readScore(?\Redis $redis) : ?float {
        if(!$redis){
            return null;
        }
        $score = $redis->get(BackpressureManager::KEY_P_SKIP);

        return ($score === false) ? null : (float)$score;
    }

    /**
     * php-fpm 워커 현황. limit 은 BackpressureManager::WORKER_LIMIT 을 쓴다 —
     * fpm status 는 설정된 한도를 알려주지 않고, 이 상수가 pm.max_children 의
     * 짝으로 함께 움직이도록 관리되고 있다(static/php/fpm-pool.conf 참고).
     * @return array
     */
    protected function workerStatus() : array {
        if(is_null($status = LocalStatus::fpmStatus())){
            return ['available' => false, 'limit' => BackpressureManager::WORKER_LIMIT];
        }

        return [
            'available'          => true,
            'active'             => (int)($status['active processes'] ?? 0),
            'idle'               => (int)($status['idle processes'] ?? 0),
            'total'              => (int)($status['total processes'] ?? 0),
            'limit'              => BackpressureManager::WORKER_LIMIT,
            'queue'              => (int)($status['listen queue'] ?? 0),
            // 프로세스 재시작 시 0 으로 리셋되는 누적값이다 (절대량으로 비교 금지)
            'maxChildrenReached' => (int)($status['max children reached'] ?? 0),
            'slowRequests'       => (int)($status['slow requests'] ?? 0),
        ];
    }

    /**
     * 이 컨테이너(pf)의 메모리. 압력 판단 기준은 usage 가 아니라 워킹셋이다
     * (usage 는 회수 가능한 page cache 를 포함해 가동시간에 비례해 올라간다).
     * @return array
     */
    protected function memoryStatus() : array {
        $workingSet = CgroupMemory::readWorkingSetBytes();
        // readLimitBytes() 가 이미 정규화한다 (미설정/무제한 → null)
        $limit      = CgroupMemory::readLimitBytes();

        if(is_null($workingSet)){
            return ['available' => false];
        }

        return [
            'available'     => true,
            'workingSetMb'  => (int)round($workingSet / 1048576),
            'limitMb'       => is_null($limit) ? null : (int)round($limit / 1048576),
            'percent'       => is_null($limit) || $limit <= 0
                ? null
                : round($workingSet * 100 / $limit, 1),
        ];
    }

    /**
     * 호스트 CPU. 이 컨테이너가 아니라 박스 전체 값이다.
     * loadAverage 는 코어 수 없이는 해석할 수 없으므로 cores/perCore 를 같이 낸다.
     * @return array
     */
    protected function cpuStatus() : array {
        $load     = HostLoad::loadAverage();
        $cores    = HostLoad::cores();
        $pressure = HostLoad::cpuPressure();

        return [
            'scope'    => 'host',
            'cores'    => $cores ? : null,
            'load1'    => $load ? $load[0] : null,
            'load5'    => $load ? $load[1] : null,
            'load15'   => $load ? $load[2] : null,
            // 1.0 이면 코어가 꽉 찬 상태 — 코어 수가 다른 박스로 옮겨도 뜻이 유지된다
            'perCore'  => ($load && $cores) ? round($load[0] / $cores, 2) : null,
            // "실행 대기로 지연된 시간의 비율(%)" — loadavg 보다 체감에 가깝다
            'pressure' => $pressure,
        ];
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
