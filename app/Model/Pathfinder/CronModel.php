<?php


namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;

class CronModel extends AbstractPathfinderModel {

    /**
     * @var string
     */
    protected $table = 'cron';

    /**
     * cron log status flags
     */
    const STATUS = [
        'unknown' => [
            'type' => 'warning',
            'icon' => 'question',
            'msg' => 'No status information available'
        ],
        'dbError' => [
            'type' => 'warning',
            'icon' => 'fa-exclamation-triangle',
            'msg' => 'Failed to sync job data with DB'
        ],
        'notExecuted' => [
            'type' => 'hint',
            'icon' => 'fa-bolt',
            'msg' => 'Has not been executed'
        ],
        'notFinished' => [
            'type' => 'danger',
            'icon' => 'fa-clock',
            'msg' => 'Not finished within max exec. time'
        ],
        'inProgress' => [
            'type' => 'success',
            'icon' => 'fa-play',
            'msg' => 'Started. In execution…'
        ],
        'isPaused' => [
            'type' => 'warning',
            'icon' => 'fa-pause',
            'msg' => 'Paused. No execution on next time trigger (skip further execution)'
        ],
        'onHold' => [
            'type' => 'information',
            'icon' => 'fa-history fa-flip-horizontal',
            'msg' => 'Is active. Waiting for next trigger…'
        ]
    ];

    /**
     * @var array
     */
    protected $fieldConf = [
        'name' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => '',
            'index' => true,
            'unique' => true,
            'validate' => 'notEmpty'
        ],
        'handler' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
            'default' => '',
            'index' => true,
            'unique' => true,
            'validate' => 'notEmpty'
        ],
        'expr' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => '',
            'validate' => 'notEmpty'
        ],
        'isPaused' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 0
        ],
        'lastExecStart' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'lastExecEnd' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'lastExecMemPeak' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'lastExecState' => [
            'type' => self::DT_JSON
        ],
        'avgCpuTime' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'maxCpuTime' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'avgExecDuration' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'maxExecDuration' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'maxMemPeak' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
            'default' => null
        ],
        'execCount' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'default' => 0
        ],
        'failCount' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'default' => 0
        ],
        'lastFailReset' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'lastAlertCpu' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'lastAlertMem' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'lastAlertFail' => [
            'type' => Schema::DT_DOUBLE,
            'nullable' => true,
            'default' => null
        ],
        'history' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\CronHistoryModel', 'cronId']
        ]
    ];

    /**
     * set data by associative array
     * @param array $data
     */
    public function setData(array $data){
        $this->copyfrom($data, [
            'handler', 'expr', 'lastExecStart', 'lastExecEnd', 'lastExecMemPeak', 'lastExecState',
            'avgCpuTime', 'maxCpuTime', 'avgExecDuration', 'maxExecDuration', 'maxMemPeak', 'execCount', 'failCount', 'lastFailReset', 'lastAlertCpu', 'lastAlertMem', 'lastAlertFail'
        ]);
    }

    /**
     * get data
     * @return object
     */
    public function getData(){
        $data                   = (object) [];
        $data->id               = $this->_id;
        $data->name             = $this->name;
        $data->handler          = $this->handler;
        $data->expr             = $this->expr;
        $data->logFile          = $this->logFileExists();

        $data->lastExecStart    = $this->lastExecStart;
        $data->lastExecEnd      = $this->lastExecEnd;
        $data->lastExecMemPeak  = $this->lastExecMemPeak;
        $data->lastExecDuration = $this->getExecDuration();
        $data->lastExecState    = $this->lastExecState;
        
        $data->avgCpuTime       = $this->avgCpuTime;
        $data->maxCpuTime       = $this->maxCpuTime;
        $data->avgExecDuration  = $this->avgExecDuration;
        $data->maxExecDuration  = $this->maxExecDuration;
        $data->maxMemPeak       = $this->maxMemPeak;
        $data->execCount        = $this->execCount;
        $data->failCount        = $this->failCount;
        $data->lastFailReset    = $this->lastFailReset;
        $data->lastAlertCpu     = $this->lastAlertCpu;
        $data->lastAlertMem     = $this->lastAlertMem;
        $data->lastAlertFail    = $this->lastAlertFail;

        $data->isPaused         = $this->isPaused;
        $data->status           = $this->getStatus();
        
        $historyData            = $this->getHistory(true);
        $data->history          = $historyData;

        // P50, P95 Calculation
        $data->p50Duration = null;
        $data->p95Duration = null;
        $data->p50CpuTime = null;
        $data->p95CpuTime = null;

        if (!empty($historyData)) {
            $durations = [];
            $cpuTimes = [];
            foreach ($historyData as $h) {
                if (isset($h['lastExecDuration'])) $durations[] = (float)$h['lastExecDuration'];
                if (isset($h['cpuTime'])) $cpuTimes[] = (float)$h['cpuTime'];
                if (isset($h['ioTime'])) $ioTimes[] = (float)$h['ioTime'];
            }

            if (!empty($durations)) {
                sort($durations);
                $count = count($durations);
                $data->p50Duration = $durations[(int)floor($count * 0.50)];
                $data->p95Duration = $durations[(int)floor($count * 0.95)];
            }
            if (!empty($cpuTimes)) {
                sort($cpuTimes);
                $count = count($cpuTimes);
                $data->p50CpuTime = $cpuTimes[(int)floor($count * 0.50)];
                $data->p95CpuTime = $cpuTimes[(int)floor($count * 0.95)];
            }
            if (!empty($ioTimes)) {
                sort($ioTimes);
                $count = count($ioTimes);
                $data->p50IoTime = $ioTimes[(int)floor($count * 0.50)];
                $data->p95IoTime = $ioTimes[(int)floor($count * 0.95)];
            }
        }

        return $data;
    }

    /**
     * setter for system alias
     * @param string $lastExecStart
     * @return string
     */
    public function set_lastExecStart($lastExecStart){
        $this->logState();
        return $lastExecStart;
    }

    /**
     * log execution "state" for prev run in 'history' column
     */
    protected function logState(){
        // reset data from last run
        $this->lastExecEnd = null;
        $this->lastExecMemPeak = null;
    }

    /**
     * @param bool $addLastIfFinished
     * @return array
     * @throws \Exception
     */
    protected function getHistory(bool $addLastIfFinished = false) : array {
        $history = [];
        $historyModel = new CronHistoryModel();
        $records = $historyModel->find(
            ['cronId = ?', $this->_id],
            ['order' => 'created DESC', 'limit' => 100]
        );
        if($records){
            foreach($records as $record){
                $history[] = [
                    'lastExecDuration' => $record->lastExecDuration,
                    'cpuTime' => $record->cpuTime,
                    'ioTime' => $record->ioTime,
                    'lastExecMemPeak' => $record->lastExecMemPeak,
                    'created' => clone $record->created
                ];
            }
        }

        if($addLastIfFinished && $this->inExec() && !$this->isTimedOut()){
            array_unshift($history, [
                'lastExecStart' => $this->lastExecStart,
                'status' => array_intersect(array_keys($this->getStatus()), ['inProgress', 'notFinished'])
            ]);
        }

        return $history;
    }

    /**
     * get current job status based on its current data
     * @return array
     * @throws \Exception
     */
    protected function getStatus() : array {
        $status = [];

        if($this->isPaused){
            $status['isPaused'] = self::STATUS['isPaused'];
        }

        if($this->inExec() && !$this->isTimedOut()){
            $status['inProgress'] = self::STATUS['inProgress'];
        }

        if(empty($status)){
            $status['onHold'] = self::STATUS['onHold'];
        }

        if($this->isTimedOut()){
            $status['notFinished'] = self::STATUS['notFinished'];
        }

        if(!$this->lastExecStart){
            $status['notExecuted'] = self::STATUS['notExecuted'];
        }

        return empty($status) ? ['unknown' => self::STATUS['unknown']] : array_reverse($status);
    }

    /**
     * based on the data on DB, job is marked at "in progress"
     * @return bool
     */
    protected function inExec() : bool {
        return $this->lastExecStart && !$this->lastExecEnd;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    protected function isTimedOut() : bool {
        $timedOut = false;
        if($this->lastExecStart){
            $timezone = self::getF3()->get('getTimeZone')();
            $startTime = \DateTime::createFromFormat(
                'U.u',
                number_format($this->lastExecStart, 6, '.', ''),
                $timezone
            );

            $timeBuffer = 60 * 60;
            $startTime->add(new \DateInterval('PT' . $timeBuffer . 'S'));

            if($this->lastExecEnd){
                $endTime = \DateTime::createFromFormat(
                    'U.u',
                    number_format($this->lastExecEnd, 6, '.', ''),
                    $timezone
                );
            }else{
                $endTime = new \DateTime('now', $timezone);
            }

            $timedOut = $startTime < $endTime;
        }

        return $timedOut;
    }

    /**
     * @return float|null
     */
    protected function getExecDuration() : ?float {
        $duration = null;
        if($this->lastExecStart && $this->lastExecEnd){
            $duration = (float)$this->lastExecEnd - (float)$this->lastExecStart;
        }

        return $duration;
    }

    /**
     * extract function name from $this->handler
     * -> it is used for the log file name
     * @return string|null
     */
    protected function getLogFileName() : ?string {
        return ($this->handler && preg_match('/^.*->(\w+)$/', $this->handler,$m)) ? 'cron_' . $m[1] . '.log' : null;
    }

    /**
     * checks whether a log file exists for this cronjob
     * -> will be created after job execution
     * @return string
     */
    protected function logFileExists() : ?string {
        $filePath = null;
        if($file = $this->getLogFileName()){
            $filePath = is_file($path = self::getF3()->get('LOGS') . $file) ? $path : null;
        }
        return $filePath;
    }
}