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
     * 직전 /proc/stat 스냅샷. CPU 사용률은 누적 카운터의 **차이**로만 구할 수 있어서
     * 이전 시점을 어딘가 들고 있어야 한다. 캐시 주기(30s)가 그대로 측정 창이 된다.
     */
    const CPU_PREV_KEY = 'PF_CPU_STAT_PREV';
    const CPU_PREV_TTL = 3600;

    /**
     * 측정 창이 이보다 길면 "지금 사용률"이라고 부를 수 없다(아무도 안 들어온 시간대).
     */
    const CPU_MAX_WINDOW_SECONDS = 600;

    /**
     * 다른 사람 트래킹과 비교할 때 "활성"으로 칠 범위
     */
    const PEER_WINDOW_MINUTES = 10;

    /**
     * 신호등 임계값 — backpressure score 기준.
     * 새 임계값을 발명하지 않고 이미 실전 검증된 복합 점수를 재사용한다.
     * (실패율 40 + 메모리 30 + 동시성 20 + 지연 10 가중합, 히스테리시스 포함)
     */
    const SCORE_WARN     = 10;
    const SCORE_DEGRADED = 30;

    /**
     * 내 위치 갱신이 이 초를 넘으면 "지연", getLogInactiveTime()(기본 180s)을 넘으면 "끊김".
     * 폴링 주기 5s / ESI location 캐시 5s 기준이라, 30s 는 여러 번 연속 실패했다는 뜻이다.
     */
    const TRACKING_LAG_SECONDS = 30;

    /**
     * GET /api/rest/ServerStatus
     * @param \Base $f3
     */
    public function get(\Base $f3){
        $status = $this->getStatus();

        // 트래킹 신선도는 **캐릭터마다 다르다**. 서버 공통 캐시(PF_SERVER_STATUS)에
        // 넣으면 먼저 호출한 사람의 값이 30초 동안 남들에게 그대로 보인다.
        // 그래서 캐시 밖에서 매 요청 계산한다 (조회 1건이라 비용도 무시할 수준).
        $status['tracking'] = $this->trackingStatus();

        $this->out($status);
    }

    /**
     * 내 캐릭터 위치 로그가 얼마나 신선한가.
     * "웜홀 이동이 반영되고 있나?" 를 유저가 스스로 판단할 수 있게 하는 값이다 —
     * 지금까지는 안 그려지면 원인을 알 방법이 없었다.
     * @return array
     */
    protected function trackingStatus() : array {
        if(is_null($character = $this->getCharacter())){
            return ['available' => false];
        }

        if(is_null($log = $character->getLog()) || empty($log->updated)){
            // 로그인은 했지만 아직 위치가 잡히지 않은 상태 (게임 미접속 등)
            return ['available' => true, 'state' => 'none', 'ageSeconds' => null, 'systemName' => null];
        }

        $age      = max(0, time() - strtotime($log->updated));
        $inactive = \Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel::getLogInactiveTime();

        if($age >= $inactive){
            $state = 'stale';
        }elseif($age >= self::TRACKING_LAG_SECONDS){
            $state = 'lag';
        }else{
            $state = 'ok';
        }

        return [
            'available'            => true,
            'state'                => $state,
            'ageSeconds'           => $age,
            'inactiveAfterSeconds' => $inactive,
            'systemName'           => $log->systemName ? : null,
        ];
    }

    /**
     * 캐시된 스냅샷을 돌려주고, 없으면 새로 만든다.
     * @return array
     */
    protected function getStatus() : array {
        $redis = $this->getRedis();

        // pconnect 이후에 Redis 가 죽거나 AUTH 가 어긋나면 get() 이 RedisException 을 던진다.
        // 이 엔드포인트는 이제 모든 클라이언트가 2분마다 치므로, 잡지 않으면
        // Redis 순단이 곧 500 버스트가 된다. 캐시를 못 읽으면 그냥 새로 만들면 된다.
        if($redis){
            try{
                $cached = $redis->get(self::CACHE_KEY);
                if(is_string($cached) && $cached !== ''){
                    $decoded = json_decode($cached, true);
                    if(is_array($decoded)){
                        return $decoded;
                    }
                }
            }catch(\Throwable $e){
                // 캐시 미스로 취급하고 계속 진행한다
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
        $cpu    = $this->cpuStatus($redis);
        $peers  = $this->peerTracking();

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
            // 남들 트래킹 신선도. 서버 공통 값이라 캐시에 담아도 된다
            // (내 값은 캐시 밖에서 매 요청 계산해 tracking 으로 따로 붙인다)
            'peers'      => $peers,
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
        // 큐가 쌓였다는 것은 이미 체감 지연이다. 점수는 히스테리시스 때문에 뒤늦게
        // 따라오므로, 점수를 못 읽는 상황이라도 이 신호만으로 warn 을 띄운다.
        // (예전에는 score 가 null 이면 곧장 unknown 이라 이 승격에 닿지 못했다)
        $queued = (int)($worker['queue'] ?? 0) > 0;

        if(is_null($score)){
            return $queued ? 'warn' : 'unknown';
        }
        if($score >= self::SCORE_DEGRADED){
            return 'degraded';
        }
        if($score >= self::SCORE_WARN || $queued){
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

        try{
            $score = $redis->get(BackpressureManager::KEY_P_SKIP);
        }catch(\Throwable $e){
            // 측정 불가는 0(한가함)이 아니라 null(모름)이다
            return null;
        }

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

        // 박스 전체. 이 서버에는 pf 말고도 pfdb(900MB)·redis(320MB)·pf-socket(200MB)·
        // traefik 이 같이 산다. "서버 메모리가 괜찮냐"는 질문의 답은 컨테이너 하나가
        // 아니라 호스트 쪽이고, 컨테이너 값만 보면 박스가 꽉 차가는 것을 놓친다.
        $host = HostLoad::memory();

        // 박스 사용량에서 패파 몫을 뺀 나머지 = DB·Redis·소켓·traefik·OS 합계.
        // 컨테이너별로 쪼개려면 docker.sock 이 필요한데, 인터넷에 노출된 이 컨테이너에
        // 그것을 주는 것은 사실상 호스트 root 권한을 주는 것이라 하지 않는다.
        // 개별 내역 없이도 "패파가 먹는 몫 / 나머지 / 여유"는 이 값들로 나눌 수 있다.
        $otherMb = null;
        if($host && !is_null($workingSet)){
            $otherMb = max(0, $host['usedMb'] - (int)round($workingSet / 1048576));
        }

        return [
            'host'      => $host,
            'otherMb'   => $otherMb,
            'container' => is_null($workingSet)
                ? ['available' => false]
                : [
                    'available'    => true,
                    'name'         => '패파',
                    'workingSetMb' => (int)round($workingSet / 1048576),
                    'limitMb'      => is_null($limit) ? null : (int)round($limit / 1048576),
                    'percent'      => is_null($limit) || $limit <= 0
                        ? null
                        : round($workingSet * 100 / $limit, 1),
                ],
        ];
    }

    /**
     * 호스트 CPU. 이 컨테이너가 아니라 박스 전체 값이다.
     * loadAverage 는 코어 수 없이는 해석할 수 없으므로 cores/perCore 를 같이 낸다.
     * @return array
     */
    protected function cpuStatus(?\Redis $redis) : array {
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
            // 코어별 실사용률 (loadavg 와 달리 "지금 얼마나 돌고 있나"를 직접 준다)
            'usage'    => $this->cpuUsage($redis),
        ];
    }

    /**
     * 코어별 CPU 사용률. /proc/stat 은 부팅 이후 누적값이라 두 시점의 차이로만 구할 수 있다.
     * 직전 스냅샷을 Redis 에 두고 캐시 주기(30s)를 그대로 측정 창으로 쓴다.
     *
     * @param \Redis|null $redis
     * @return array|null ['cores'=>[float,...],'totalPct'=>float,'windowSeconds'=>int]
     */
    protected function cpuUsage(?\Redis $redis) : ?array {
        if(!$redis || is_null($current = HostLoad::cpuStat())){
            return null;
        }

        $stamp = time();
        $prev = null;

        try{
            $raw = $redis->get(self::CPU_PREV_KEY);
            if(is_string($raw) && $raw !== ''){
                $decoded = json_decode($raw, true);
                $prev = is_array($decoded) ? $decoded : null;
            }
            // 다음 호출을 위한 기준점. 이번에 값을 못 내더라도 반드시 남긴다
            $redis->setex(self::CPU_PREV_KEY, self::CPU_PREV_TTL, json_encode([
                'stamp' => $stamp,
                'cores' => $current,
            ]));
        }catch(\Throwable $e){
            return null;
        }

        if(!is_array($prev) || empty($prev['cores'])){
            return null;
        }

        $window = $stamp - (int)($prev['stamp'] ?? 0);
        if($window <= 0 || $window > self::CPU_MAX_WINDOW_SECONDS){
            // 아무도 안 들어온 시간대의 평균은 "지금 사용률"이 아니다
            return null;
        }

        $cores = [];
        $sumTotal = 0;
        $sumIdle = 0;

        foreach($current as $key => $cur){
            if(!isset($prev['cores'][$key])){
                continue;
            }
            $deltaTotal = $cur['total'] - (int)$prev['cores'][$key]['total'];
            $deltaIdle  = $cur['idle'] - (int)$prev['cores'][$key]['idle'];

            if($deltaTotal <= 0){
                continue;
            }

            $cores[] = round((1 - ($deltaIdle / $deltaTotal)) * 100, 1);
            $sumTotal += $deltaTotal;
            $sumIdle += $deltaIdle;
        }

        if(!$cores){
            return null;
        }

        return [
            'cores'         => $cores,
            'totalPct'      => ($sumTotal > 0) ? round((1 - ($sumIdle / $sumTotal)) * 100, 1) : null,
            'windowSeconds' => $window,
        ];
    }

    /**
     * 다른 접속자들의 위치 갱신 신선도.
     * "서버가 느린 건지 나만 느린 건지"는 내 값만 봐서는 구분할 수 없다.
     *
     * @return array|null
     */
    protected function peerTracking() : ?array {
        try{
            $rows = $this->getDB()->exec(
                'SELECT COUNT(*) AS `cnt`, ' .
                'ROUND(AVG(TIMESTAMPDIFF(SECOND, `updated`, UTC_TIMESTAMP()))) AS `avgAge` ' .
                'FROM `character_log` ' .
                'WHERE `updated` > DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)',
                [':minutes' => self::PEER_WINDOW_MINUTES]
            );
        }catch(\Throwable $e){
            return null;
        }

        if(empty($rows[0]) || !(int)$rows[0]['cnt']){
            return null;
        }

        return [
            'count'         => (int)$rows[0]['cnt'],
            'avgAgeSeconds' => is_null($rows[0]['avgAge']) ? null : (int)$rows[0]['avgAge'],
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
                if(!@$redis->pconnect(
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
