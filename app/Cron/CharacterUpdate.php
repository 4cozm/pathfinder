<?php

/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 30.07.2015
 * Time: 19:35
 */

namespace Exodus4D\Pathfinder\Cron;


use Exodus4D\Pathfinder\Model\Pathfinder;

class CharacterUpdate extends AbstractCron
{

    /**
     * default character_log time until a log entry get re-checked by cronjob
     */
    const CHARACTER_LOG_INACTIVE            =   180;

    /**
     * max count of "inactive" character log data that will be checked for offline status
     */
    const CHARACTERS_UPDATE_LOGS_MAX        =   10;

    /**
     * get "inactive" time for character log data in seconds
     * @param \Base $f3
     * @return int
     */
    protected function getCharacterLogInactiveTime(\Base $f3)
    {
        $logInactiveTime = (int)$f3->get('PATHFINDER.CACHE.CHARACTER_LOG_INACTIVE');
        return ($logInactiveTime >= 0) ? $logInactiveTime : self::CHARACTER_LOG_INACTIVE;
    }

    /**
     * delete all character log data that have not changed since X seconds
     * -> see deactivateLogData()
     * >> php index.php "/cron/deleteLogData"
     * @param \Base $f3
     * @throws \Exception
     */
    function deleteLogData(\Base $f3)
    {
        $this->logStart(__FUNCTION__, false);
        $logInactiveTime = $this->getCharacterLogInactiveTime($f3);

        /**
         * @var $characterLogModel Pathfinder\CharacterLogModel
         */
        $characterLogModel = Pathfinder\AbstractPathfinderModel::getNew('CharacterLogModel');

        // find character logs that were not checked recently and update
        $characterLogs = $characterLogModel->find([
            'TIMESTAMPDIFF(SECOND, updated, NOW() ) > :lifetime',
            ':lifetime' => $logInactiveTime
        ], [
            'order' => 'updated asc',
            'limit' => self::CHARACTERS_UPDATE_LOGS_MAX
        ]);

        $total = 0;
        $count = 0;

        if (is_object($characterLogs)) {
            $total = count($characterLogs);
            foreach ($characterLogs as $characterLog) {
                /**
                 * @var $characterLog Pathfinder\CharacterLogModel
                 */
                if (is_object($characterLog->characterId)) {
                    if ($accessToken = $characterLog->characterId->getAccessToken()) {
                        if ($characterLog->characterId->isOnline($accessToken)) {
                            // force characterLog as "updated" even if no changes were made
                            $characterLog->touch('updated');
                            $characterLog->save();
                        } else {
                            $characterLog->erase();
                        }
                    } else {
                        // no valid $accessToken. (e.g. ESI is down; or invalid `refresh_token` found
                        $characterLog->erase();
                    }
                } else {
                    // character_log does not have a character assigned -> delete
                    $characterLog->erase();
                }

                $count++;
            }
        }

        $importCount = $total;

        $this->logEnd(__FUNCTION__, $total, $count, $importCount);
    }

    /**
     * clean up outdated character data e.g. kicked until status
     * >> php index.php "/cron/cleanUpCharacterData"
     * @param \Base $f3
     * @throws \Exception
     */
    function cleanUpCharacterData(\Base $f3)
    {
        $this->logStart(__FUNCTION__, false);

        /**
         * @var $characterModel Pathfinder\CharacterModel
         */
        $characterModel = Pathfinder\AbstractPathfinderModel::getNew('CharacterModel');

        $characters = $characterModel->find([
            'active = :active AND TIMESTAMPDIFF(SECOND, kicked, NOW() ) > 0',
            ':active' => 1
        ]);

        if (is_object($characters)) {
            foreach ($characters as $character) {
                /**
                 * @var $character Pathfinder\CharacterModel
                 */
                $character->kick();
                $character->save();
            }
        }

        $this->logEnd(__FUNCTION__);
    }

    /**
     * delete expired character authentication data
     * authentication data is used for cookie based login
     * >> php index.php "/cron/deleteAuthenticationData"
     * @param \Base $f3
     * @throws \Exception
     */
    function deleteAuthenticationData(\Base $f3)
    {
        $this->logStart(__FUNCTION__, false);

        /**
         * @var $authenticationModel Pathfinder\CharacterAuthenticationModel
         */
        $authenticationModel = Pathfinder\AbstractPathfinderModel::getNew('CharacterAuthenticationModel');

        // find expired authentication data
        $authentications = $authenticationModel->find([
            '(expires - NOW()) <= 0'
        ]);

        if (is_object($authentications)) {
            foreach ($authentications as $authentication) {
                $authentication->erase();
            }
        }

        $this->logEnd(__FUNCTION__);
    }

    /**
     * Update character location logs via ESI and push updates to websocket server.
     * >> php index.php "/cron/updateCharacterLogs"
     *
     * 목적:
     * - 웹 클라이언트(updateUserData)가 없더라도 서버가 주기적으로 updateLog()를 돌린다
     * - updateLog 결과를 websocket(characterUpdate)으로 push해서 mapSubscriptions가 갱신되게 한다
     */
    function updateCharacterLogs(\Base $f3)
    {
        $this->logStart(__FUNCTION__, false);

        // 너무 많은 ESI 호출 방지: 10계정이면 10~20도 OK, 필요시 조절
        $max = (int)$f3->get('PATHFINDER.CACHE.CHARACTER_LOGS_REFRESH_MAX');
        if ($max <= 0) $max = 20;

        // 갱신 주기(초): 최근에 갱신된 캐릭터는 스킵
        $refreshSec = (int)$f3->get('PATHFINDER.CACHE.CHARACTER_LOGS_REFRESH_SEC');
        if ($refreshSec <= 0) $refreshSec = 60;

        /** @var $characterModel \Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel */
        $characterModel = \Exodus4D\Pathfinder\Model\Pathfinder\AbstractPathfinderModel::getNew('CharacterModel');

        // logLocation 활성화 + active 캐릭터만
        $characters = $characterModel->find([
            'active = :active AND logLocation = :logLocation',
            ':active' => 1,
            ':logLocation' => 1
        ], [
            'order' => 'lastLogin DESC',
            'limit' => $max
        ]);

        $total = is_object($characters) ? count($characters) : 0;
        $count = 0;
        $updated = 0;

        if (is_object($characters)) {
            foreach ($characters as $character) {
                /** @var $character \Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel */
                try {
                    // basic scopes 없는 캐릭터는 스킵 (updateLog 내부에서도 걸리지만 비용 절약)
                    if (!$character->hasBasicScopes()) {
                        $count++;
                        continue;
                    }

                    // 최근에 갱신된 로그면 스킵
                    $log = $character->getLog();
                    if ($log && !empty($log->updated)) {
                        $age = time() - strtotime($log->updated);
                        if ($age >= 0 && $age < $refreshSec) {
                            $count++;
                            continue;
                        }
                    }

                    // markUpdated => 변화 없어도 updated touch (선택)
                    $character->updateLog(['markUpdated' => true]);

                    // ✅ websocket에 캐릭터 데이터 push (mapSubscriptions 갱신의 핵심)
                    $character->broadcastCharacterUpdate();

                    $updated++;
                } catch (\Throwable $e) {
                    // 개별 캐릭터 실패는 무시하고 다음 진행 (ESI 순간 오류/타임아웃 대비)
                    // 필요하면 error_log($e->getMessage()) 정도만 남겨도 됨
                }

                $count++;
            }
        }

        // logEnd는 기존 패턴에 맞춰서
        $this->logEnd(__FUNCTION__, $total, $count, $updated);
    }
    function updateStandaloneTrackedLogs(\Base $f3)
    {
        $this->logStart(__FUNCTION__, false);

        $dir = '/var/www/html/pathfinder/tmp/pf';
        $t0  = microtime(true);

        // per-conn 파일 패턴: standalone_presence_map_{mapId}_conn_{resourceId}.json
        // 구형 패턴(standalone_presence_map_{mapId}.json)도 함께 수집해 무중단 전환 지원
        $files      = glob($dir . '/standalone_presence_map_*.json') ?: [];
        $filesCount = count($files);

        // helper: Map.php의 protected updateMapByCharacter() 호출 래퍼
        $tracker = new class extends \Exodus4D\Pathfinder\Controller\Api\Map {
            public function track(
                \Exodus4D\Pathfinder\Model\Pathfinder\MapModel $map,
                \Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel $character
            ): \Exodus4D\Pathfinder\Model\Pathfinder\MapModel {
                $positions = [
                    'defaults' => [],
                    'location' => [],
                ];
                return $this->updateMapByCharacter($map, $character, $positions);
            }
        };

        $charsTotal     = 0;
        $charsProcessed = 0;
        $updated        = 0;
        $skippedExpired = 0;
        $errors         = 0;
        $now            = time();

        // --- STEP 1: 모든 파일을 읽어 TTL 유효한 것만 mapId => set(characterId) 로 merge ---
        // 여러 helper가 같은 mapId에 대해 각자 per-conn 파일을 쓰므로, 합집합을 구해야
        // 전체 활성 캐릭터 집합이 유지된다.
        $mergedByMap = [];   // [mapId => [cid => true]]

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (!$raw) continue;

            $p = json_decode($raw, true);
            if (!is_array($p)) continue;

            $mapId = (int)($p['mapId'] ?? 0);
            $ts    = (int)($p['ts']    ?? 0);
            $ttl   = (int)($p['ttl']   ?? 0);
            $chars = $p['chars']        ?? [];

            if ($mapId <= 0 || $ts <= 0 || $ttl <= 0 || !is_array($chars)) continue;

            if ($now > ($ts + $ttl)) {
                $skippedExpired++;
                continue;
            }

            foreach ($chars as $cid) {
                $cid = is_array($cid) ? (int)($cid['id'] ?? 0) : (int)$cid;
                if ($cid > 0) {
                    $mergedByMap[$mapId][$cid] = true;
                }
            }
        }

        // --- STEP 2: mapId 별로 한 번만 map 로드 + character 처리 ---
        foreach ($mergedByMap as $mapId => $cidSet) {
            $map = \Exodus4D\Pathfinder\Model\Pathfinder\AbstractPathfinderModel::getNew('MapModel');
            $map->getById($mapId);
            if (!$map->valid()) continue;

            foreach (array_keys($cidSet) as $cid) {
                $cid = (int)$cid;
                $charsTotal++;

                try {
                    $character = \Exodus4D\Pathfinder\Model\Pathfinder\AbstractPathfinderModel::getNew('CharacterModel');
                    $character->getById($cid);
                    if (!$character->valid()) {
                        error_log("[daemon][char] id={$cid} skip=CHAR_NOT_FOUND");
                        continue;
                    }

                    if (!$character->hasBasicScopes()) {
                        error_log("[daemon][char] id={$cid} skip=NO_SCOPES");
                        continue;
                    }

                    if (!$map->hasAccess($character)) {
                        error_log("[daemon][char] id={$cid} skip=NO_ACCESS map={$mapId}");
                        continue;
                    }

                    $charsProcessed++;

                    // 1) 위치 갱신(ESI)
                    try {
                        $character->updateLog(['markUpdated' => true]);
                    } catch (\Throwable $e) {
                        error_log("[daemon][char] id={$cid} skip=UPDATELOG_FAIL msg=" . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    // 2) 맵 트래킹
                    try {
                        $tracker->track($map, $character);
                    } catch (\Throwable $e) {
                        error_log("[daemon][char] id={$cid} skip=TRACK_FAIL msg=" . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    // 3) WS 캐릭 업데이트 push
                    try {
                        $character->broadcastCharacterUpdate();
                    } catch (\Throwable $e) {
                        error_log("[daemon][char] id={$cid} skip=BROADCAST_FAIL msg=" . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    $updated++;
                } catch (\Throwable $e) {
                    error_log("[daemon][char] id={$cid} skip=FATAL msg=" . $e->getMessage());
                    $errors++;
                    continue;
                }
            }
        }

        $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

        $this->logEnd(__FUNCTION__, $charsTotal, $charsTotal, $updated);

        return [
            'dir'            => $dir,
            'files'          => $filesCount,
            'charsTotal'     => $charsTotal,
            'charsProcessed' => $charsProcessed,
            'updated'        => $updated,
            'expiredFiles'   => $skippedExpired,
            'errors'         => $errors,
            'elapsedMs'      => $elapsedMs,
        ];
    }

    /**
     * 만료된 전투 집계 작업을 찾아 DISCORD_ALERT_WEBHOOK_URL로 임베드 전송.
     * dmc_tasks:active SET을 조회한 뒤, dmc_tasks:{requestId}가 없으면(TTL 만료) 메타에서
     * requesterName·기간을 읽어 웹훅 전송 후 active에서 제거.
     *
     * @param \Base $f3
     * @return void
     */
    public function checkExpiredCombatAggregations(\Base $f3)
    {
        $dsn = (string)getenv('REDIS_DSN');
        if ($dsn === '') {
            return;
        }
        $webhookUrl = (string)getenv('DISCORD_ALERT_WEBHOOK_URL');
        if ($webhookUrl === '') {
            return;
        }
        try {
            $redis = new \Predis\Client($dsn);
            $activeIds = $redis->smembers('dmc_tasks:active');
            if (!is_array($activeIds)) {
                return;
            }
            foreach ($activeIds as $requestId) {
                $requestId = (string)$requestId;
                if ($requestId === '') {
                    continue;
                }
                $taskKey = 'dmc_tasks:' . $requestId;
                if ($redis->exists($taskKey)) {
                    continue; // 아직 유효
                }
                $metaKey = 'dmc_tasks:meta:' . $requestId;
                $metaRaw = $redis->get($metaKey);
                $redis->del($metaKey);
                $redis->srem('dmc_tasks:active', [$requestId]);

                $meta = $metaRaw ? json_decode($metaRaw, true) : null;
                $requesterName = is_array($meta) && isset($meta['requesterName']) ? (string)$meta['requesterName'] : '';
                $startTime = is_array($meta) && isset($meta['startTime']) ? (string)$meta['startTime'] : '';
                $endTime = is_array($meta) && isset($meta['endTime']) ? (string)$meta['endTime'] : '';

                $embed = [
                    'title' => '전투 로그 집계 완료',
                    'description' => '5분 집계 구간이 종료되었습니다.',
                    'color' => 0x57f287,
                    'timestamp' => date('c'),
                    'fields' => [],
                ];
                if ($requesterName !== '') {
                    $embed['fields'][] = ['name' => '요청자', 'value' => $requesterName, 'inline' => true];
                }
                if ($startTime !== '' || $endTime !== '') {
                    $embed['fields'][] = [
                        'name' => '집계 기간',
                        'value' => $startTime . ' ~ ' . $endTime,
                        'inline' => false,
                    ];
                }
                $payload = json_encode(['embeds' => [$embed]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\n",
                        'content' => $payload,
                        'timeout' => 10,
                    ],
                ]);
                @file_get_contents($webhookUrl, false, $ctx);
            }
        } catch (\Throwable $e) {
            error_log('[checkExpiredCombatAggregations] ' . $e->getMessage());
        }
    }
}
