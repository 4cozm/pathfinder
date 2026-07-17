<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 17.06.2018
 * Time: 12:13
 */

namespace Exodus4D\Pathfinder\Cron;

use Exodus4D\Pathfinder\Lib\Format\Number;
use Exodus4D\Pathfinder\Lib\Metrics;
use Exodus4D\Pathfinder\Model\Pathfinder;

abstract class AbstractCron {

    /**
     * default "base" log message text
     * -> generic information data
     */
    const LOG_TEXT_BASE = '%4s/%-4s %6s done, %5s total, %8s peak, %9s exec';

    /**
     * default max_execution_time for cronJobs
     * -> should be less then execution period
     */
    const DEFAULT_MAX_EXECUTION_TIME = 50;

    /**
     * default threshold time in seconds before a running script (e.g. a large loop) should stop
     * -> so there is some time or e.g. logging,... left
     */
    const DEFAULT_EXECUTION_TIME_THRESHOLD = 3;

    /**
     * started jobs
     * @var Pathfinder\CronModel[]
     */
    protected $activeCron = [];

    /**
     * disables log file write entry for some cronJobs
     * -> either job runs too frequently, or no relevant data available for logging
     * @var array
     */
    protected $logDisabled = [];

    /**
     * set max execution time for cronJbs
     * -> Default CLI execution time is 0 == infinite!
     * php.ini settings are ignored! http://php.net/manual/en/info.configuration.php#ini.max-execution-time
     * @param int $time
     */
    protected function setMaxExecutionTime(int $time = self::DEFAULT_MAX_EXECUTION_TIME){
        ini_set('max_execution_time', $time);
    }

    /**
     * get max execution time
     * -> 0 means == infinite!
     * @return int
     */
    protected function getMaxExecutionTime() : int {
        return (int)ini_get('max_execution_time');
    }

    /**
     * checks execution time of a "long" running script
     * -> returns false if execution time is close to maxExecutionTime
     * @param float $timeTotalStart
     * @param float|null $timeCheck
     * @param int $timeThreshold
     * @return bool
     */
    protected function isExecutionTimeLeft(float $timeTotalStart, float $timeCheck = null, int $timeThreshold = self::DEFAULT_EXECUTION_TIME_THRESHOLD) : bool {
        $timeLeft = true;
        if($timeTotalMax = $this->getMaxExecutionTime()){
            $timeTotalMaxThreshold = $timeTotalStart + $timeTotalMax - $timeThreshold;
            $timeCheck = $timeCheck ? : microtime(true);
            if($timeCheck >= $timeTotalMaxThreshold){
                $timeLeft = false;
            }
        }
        return $timeLeft;
    }

    /**
     * log cronjob exec state on start
     * @param string $job
     * @param bool $logging
     */
    protected function logStart(string $job, bool $logging = true){
        $this->setMaxExecutionTime();

        $cron = \Exodus4D\Pathfinder\Lib\Cron::instance();
        if(isset($cron->jobs[$job])){
            // set "start" date for current cronjob
            $jobConf = $cron->getJobDataFromConf($cron->jobs[$job]);
            $jobConf['lastExecStart'] = $_SERVER['REQUEST_TIME_FLOAT'];
            
            // Record initial CPU usage
            if(function_exists('getrusage')){
                $ru = getrusage();
                $jobConf['lastExecState'] = [
                    'cpu_start_u' => $ru["ru_utime.tv_sec"] + $ru["ru_utime.tv_usec"] / 1000000, 
                    'cpu_start_s' => $ru["ru_stime.tv_sec"] + $ru["ru_stime.tv_usec"] / 1000000
                ];
            }

            if(($cronModel = $cron->registerJob($job, $jobConf)) instanceof Pathfinder\CronModel){
                $this->activeCron[$job] = $cronModel;
            }
        }

        if(!$logging){
            $this->logDisabled[] = $job;
        }
    }

    /**
     * log cronjob exec state on finish
     * @param string $job
     * @param int $total
     * @param int $count
     * @param int $importCount
     * @param int $offset
     * @param string $logText
     */
    protected function logEnd(string $job, int $total = 0, int $count = 0, int $importCount = 0, int $offset = 0, string $logText = ''){
        $execEnd = microtime(true);
        $memPeak = memory_get_peak_usage();
        $state = [
            'total'         => $total,
            'count'         => $count,
            'importCount'   => $importCount,
            'offset'        => $offset,
            'loop'          => 1,
            'percent'       => $total ? round(100 / $total * ($count + $offset), 1) : 100
        ];

        if(isset($this->activeCron[$job])){
            $cronModel = $this->activeCron[$job];
            
            // CPU Time Calculation
            $cpuTime = 0;
            if(function_exists('getrusage')){
                $ru = getrusage();
                $cpuEndU = $ru["ru_utime.tv_sec"] + $ru["ru_utime.tv_usec"] / 1000000;
                $cpuEndS = $ru["ru_stime.tv_sec"] + $ru["ru_stime.tv_usec"] / 1000000;
                
                $cpuStartU = 0;
                $cpuStartS = 0;
                if($lastState = $cronModel->lastExecState){
                    $cpuStartU = $lastState['cpu_start_u'] ?? 0;
                    $cpuStartS = $lastState['cpu_start_s'] ?? 0;
                }
                
                if($cpuStartU || $cpuStartS) {
                    $cpuTime = ($cpuEndU - $cpuStartU) + ($cpuEndS - $cpuStartS);
                }
            }

            $state['cpuTime'] = $cpuTime;

            // Metric Aggregation
            $execCount = $cronModel->execCount + 1;
            $duration = $execEnd - $cronModel->lastExecStart;
            $ioTime = max(0, $duration - $cpuTime);
            $state['ioTime'] = $ioTime;
            
            // EMA (Exponential Moving Average) based on 100 periods
            $alpha = 2 / (100 + 1);
            $avgExecDuration = $cronModel->avgExecDuration;
            $avgExecDuration = $avgExecDuration ? (($duration * $alpha) + ($avgExecDuration * (1 - $alpha))) : $duration;
            
            $maxExecDuration = max((float)$cronModel->maxExecDuration, $duration);
            $maxMemPeak = max((int)$cronModel->maxMemPeak, $memPeak);
            
            $avgCpuTime = $cronModel->avgCpuTime;
            $avgCpuTime = $avgCpuTime ? (($cpuTime * $alpha) + ($avgCpuTime * (1 - $alpha))) : $cpuTime;
            $maxCpuTime = max((float)$cronModel->maxCpuTime, $cpuTime);

            // 30 days Fail Reset Rule
            $failCount = $cronModel->failCount;
            $lastFailReset = $cronModel->lastFailReset ?: $execEnd;
            if ($execEnd - $lastFailReset > 2592000) {
                $failCount = 0;
                $lastFailReset = $execEnd;
            }

            if($lastState = $cronModel->lastExecState){
                if(isset($lastState['loop']) && $offset){
                    $state['loop'] = (int)$lastState['loop'] + 1;
                }
            }

            $jobConf = [
                'lastExecEnd'       => $execEnd,
                'lastExecMemPeak'   => $memPeak,
                'lastExecState'     => $state,
                'avgCpuTime'        => $avgCpuTime,
                'maxCpuTime'        => $maxCpuTime,
                'avgExecDuration'   => $avgExecDuration,
                'maxExecDuration'   => $maxExecDuration,
                'maxMemPeak'        => $maxMemPeak,
                'execCount'         => $execCount,
                'failCount'         => $failCount,
                'lastFailReset'     => $lastFailReset,
                'lastAlertCpu'      => $cronModel->lastAlertCpu,
                'lastAlertMem'      => $cronModel->lastAlertMem,
                'lastAlertFail'     => $cronModel->lastAlertFail
            ];

            // Prometheus 노출 (이미 계산된 값 재사용 — cron_history/Discord 알림과 별개)
            Metrics::counter('pf_cron_runs_total', ['job' => $job]);
            Metrics::gauge('pf_cron_last_duration_seconds', ['job' => $job], $duration);
            Metrics::gauge('pf_cron_last_cpu_seconds', ['job' => $job], $cpuTime);
            Metrics::gauge('pf_cron_last_mem_peak_bytes', ['job' => $job], $memPeak);
            Metrics::gauge('pf_cron_fail_count', ['job' => $job], $failCount);


            // Discord Webhook Logic
            $f3 = \Base::instance();
            $cpuWarningThreshold = (float)$f3->get('CRON_CPU_WARNING_THRESHOLD') ?: 10.0;
            $memWarningThreshold = (int)$f3->get('CRON_MEM_WARNING_THRESHOLD') ?: 52428800; // 50MB
            $failWarningThreshold = (int)$f3->get('CRON_FAIL_WARNING_THRESHOLD') ?: 3;
            $alertCooldown = (int)$f3->get('CRON_ALERT_COOLDOWN') ?: 3600;
            $discordWebhook = $f3->get('DISCORD_CRON_WEBHOOK_URL');

            $alertCpu = ($cpuTime > $cpuWarningThreshold) && ($execEnd - (float)$cronModel->lastAlertCpu > $alertCooldown);
            $alertMem = ($memPeak > $memWarningThreshold) && ($execEnd - (float)$cronModel->lastAlertMem > $alertCooldown);
            $alertFail = ($failCount >= $failWarningThreshold) && ($execEnd - (float)$cronModel->lastAlertFail > $alertCooldown);

            if ($discordWebhook && ($alertCpu || $alertMem || $alertFail)) {
                $msg = sprintf("⚠️ [Pathfinder Cron] Job: %s\nCPU Time: %.2fs (Max: %.2fs)\nMem Peak: %s (Max: %s)\nFail Count: %d", 
                    $job, $cpuTime, $cpuWarningThreshold, Number::instance()->bytesToString($memPeak), Number::instance()->bytesToString($memWarningThreshold), $failCount);
                
                $ch = curl_init($discordWebhook);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $msg]));
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_exec($ch);
                curl_close($ch);
                
                if ($alertCpu) $jobConf['lastAlertCpu'] = $execEnd;
                if ($alertMem) $jobConf['lastAlertMem'] = $execEnd;
                if ($alertFail) $jobConf['lastAlertFail'] = $execEnd;
            }

            // Insert into History
            // cron_history 테이블이 아직 없는 레거시 DB에서는 기록을 건너뛴다(테이블은 /setup의 "Setup DB"로 생성).
            // new CronHistoryModel() 인스턴스화 자체가 스키마 조회로 PDOException(1146)을 던지므로 먼저 존재 확인.
            $historyDb = $f3->DB->getDB(Pathfinder\AbstractPathfinderModel::DB_ALIAS);
            if (is_object($historyDb) && $historyDb->tableExists('cron_history')) {
                $historyModel = new Pathfinder\CronHistoryModel();
                $historyModel->cronId = $cronModel->_id;
                $historyModel->lastExecDuration = $duration;
                $historyModel->cpuTime = $cpuTime;
                $historyModel->ioTime = $ioTime;
                $historyModel->lastExecMemPeak = $memPeak;
                $historyModel->save();

                // Prune history (keep 100)
                $oldRecords = $historyModel->find(['cronId = ?', $cronModel->_id], ['order' => 'created DESC', 'limit' => 50, 'offset' => 100]);
                if ($oldRecords) {
                    foreach ($oldRecords as $rec) {
                        $rec->erase();
                    }
                }
            }

            $cronModel->setData($jobConf);
            $cronModel->save();
            unset($this->activeCron[$job]);
        }

        if(!in_array($job, $this->logDisabled)){
            $this->writeLog($job, $memPeak, $execEnd, $state, $logText);
        }
    }

    /**
     * get either CLI GET params OR
     * check for params from last run -> incremental import
     * @param string $job
     * @return array
     */
    protected function getParams(string $job) : array {
        $params = [];

        // check for CLI GET params
        $f3 = \Base::instance();
        if($getParams = (array)$f3->get('GET')){
            if(isset($getParams['offset'])){
                $params['offset'] = (int)$getParams['offset'];
            }
            if(isset($getParams['length']) && (int)$getParams['length'] > 0){
                $params['length'] = (int)$getParams['length'];
            }
        }

        // .. or check for logged params from last exec state (DB entry)
        if(empty($params) && isset($this->activeCron[$job])){
            if($lastState = $this->activeCron[$job]->lastExecState){
                if(isset($lastState['offset'])){
                    $params['offset'] = (int)$lastState['offset'];
                }
                if(isset($lastState['count'])){
                    $params['offset'] = (int)$params['offset'] + (int)$lastState['count'];
                }
                if(isset($lastState['loop'])){
                    $params['loop'] = (int)$lastState['loop'];
                }
            }
        }

        return $params;
    }

    /**
     * write log file for $job
     * @param string $job
     * @param int $memPeak
     * @param float $execEnd
     * @param array $state
     * @param string $logText for custom text
     */
    private function writeLog(string $job, int $memPeak = 0, float $execEnd = 0, array $state = [], string $logText = ''){
        $percent = number_format($state['percent'], 1) . '%';
        $duration = number_format(round($execEnd - $_SERVER['REQUEST_TIME_FLOAT'], 3), 3) . 's';
        $log = new \Log('cron_' . $job . '.log');

        $text = sprintf(self::LOG_TEXT_BASE,
            $state['count'], $state['importCount'], $percent, $state['total'],
            Number::instance()->bytesToString($memPeak), $duration
        );

        $text .= $logText ? $logText: '';
        $log->write($text);
    }
}