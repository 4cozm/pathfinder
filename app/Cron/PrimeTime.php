<?php

namespace Exodus4D\Pathfinder\Cron;

use Exodus4D\Pathfinder\Lib\Api\ZKillboardManager;
use Exodus4D\Pathfinder\Model\Pathfinder;
use Exodus4D\Pathfinder\Controller\LogController;

/**
 * derives a "prime time" indicator (zKillboard killmail history) for occupied/hostile
 * systems. Picked up by the existing once-a-minute "php index.php /cron" crontab
 * (already baked into the pf image, reads cron.ini) -> NOT the persistent standalone
 * daemon loop. That loop shares one long-lived DB connection across every tick, and
 * running this here empirically left real-table SELECTs silently returning empty
 * results right after the tracking job's own queries on the same connection/process
 * (root cause not fully isolated -> likely an unbuffered-query/cursor state issue).
 * The per-minute crontab gives every run a fresh PHP process/connection, which sidesteps
 * that entirely, and a zKillboard outage still can't affect the site since this only
 * ever runs from a throwaway CLI process, never inside a real request.
 */
class PrimeTime extends AbstractCron {

    /**
     * @see SystemStatusModel::$tableData
     */
    const STATUS_ID_OCCUPIED = 3;
    const STATUS_ID_HOSTILE = 4;

    /**
     * fetch/refresh prime-time data for a small batch of occupied/hostile systems
     * -> never-fetched systems are prioritized over stale refreshes
     * >> php index.php "/cron/sweepPrimeTimes"
     * @param \Base $f3
     * @throws \Exception
     */
    function sweepPrimeTimes(\Base $f3){
        $this->logStart(__FUNCTION__, false);
        $count = 0;

        $batchSize = (int)$f3->get('PATHFINDER.PRIMETIME.SWEEP_BATCH_SIZE') ?: 1;
        $refreshInterval = (int)$f3->get('PATHFINDER.PRIMETIME.REFRESH_INTERVAL') ?: 604800;

        if($pfDB = $f3->DB->getDB('PF')){
            $sql = "SELECT
                    `sys`.`id`, `sys`.`systemId`
                FROM
                    `system` `sys`
                WHERE
                    `sys`.`active` = 1 AND
                    `sys`.`statusId` IN (:occupied, :hostile) AND
                    (`sys`.`zkbCheckedAt` IS NULL OR TIMESTAMPDIFF(SECOND, `sys`.`zkbCheckedAt`, NOW()) > :refreshInterval)
                ORDER BY
                    `sys`.`zkbCheckedAt` IS NULL DESC, `sys`.`zkbCheckedAt` ASC
                LIMIT :batchSize";

            $candidates = $pfDB->exec($sql, [
                ':occupied' => self::STATUS_ID_OCCUPIED,
                ':hostile' => self::STATUS_ID_HOSTILE,
                ':refreshInterval' => $refreshInterval,
                ':batchSize' => $batchSize
            ]);

            if($candidates){
                $manager = ZKillboardManager::instance();
                $system = Pathfinder\AbstractPathfinderModel::getNew('SystemModel');

                foreach($candidates as $data){
                    try {
                        $system->getById((int)$data['id']);
                        if($system->valid()){
                            // AbstractMapTrackingModel::save($characterModel = null) always
                            // OVERWRITES updatedCharacterId with whatever gets passed in
                            // (null if omitted) -> since that field is 'validate' => 'notDry',
                            // a plain save() on a system-driven background job wipes a
                            // perfectly valid existing value and then fails its own
                            // validation. There's no "acting character" for this job, so the
                            // only correct move is to read back whoever last touched the row
                            // and hand that same character right back in.
                            $actingCharacter = $system->updatedCharacterId;
                            if(!($actingCharacter instanceof Pathfinder\CharacterModel) || $actingCharacter->dry()){
                                $actingCharacter = null;
                            }

                            $primeTime = $manager->fetchPrimeTime((int)$data['systemId'], $f3);
                            if(!is_null($primeTime)){
                                $encoded = json_encode($primeTime, JSON_INVALID_UTF8_SUBSTITUTE);
                                if($encoded !== false){
                                    // system-driven background update -> no activity log entry / no user context
                                    $system->setActivityLogging(false);
                                    $system->zkbCheckedAt = date('Y-m-d H:i:s');
                                    $system->zkbData = $encoded;
                                    $system->save($actingCharacter);
                                    $count++;
                                }
                                // encode failure -> zkbCheckedAt stays untouched, same as a fetch failure
                            }elseif($rateLimitedUntil = $manager->getRateLimitedUntil()){
                                // 429 -> cool this system down instead of retrying it next
                                // sweep (60s later), which would only make the throttling
                                // worse. zkbData is intentionally left untouched (whatever
                                // was cached before, if anything, keeps showing) -> only
                                // zkbCheckedAt is nudged forward just enough to push this
                                // row out of the WHERE clause's staleness window until the
                                // backoff passes, without needing a dedicated DB column.
                                $backoffSeconds = max(1, $rateLimitedUntil - time());
                                $cooldownSeconds = max(0, $refreshInterval - $backoffSeconds);
                                $system->setActivityLogging(false);
                                $system->zkbCheckedAt = date('Y-m-d H:i:s', time() - $cooldownSeconds);
                                $system->save($actingCharacter);
                                $system->reset();
                                // we're rate limited right now -> any other candidate in this
                                // batch would just hit the same 429 immediately. SWEEP_BATCH_SIZE
                                // is 1 by default so this rarely matters, but stay correct if
                                // it's ever raised.
                                break;
                            }
                            // any other failure -> zkbCheckedAt stays untouched -> naturally retried next sweep
                        }
                    } catch (\Throwable $e) {
                        // one bad candidate (e.g. a malformed killmail_time from the external
                        // API) must not sink the rest of the batch or skip logEnd() below
                        LogController::getLogger('ERROR')->write('sweepPrimeTimes: system ' . ($data['id'] ?? '?') . ' failed: ' . $e->getMessage());
                    }
                    $system->reset();
                }
            }
        }

        $this->logEnd(__FUNCTION__, $count, $count, $count);
    }
}
