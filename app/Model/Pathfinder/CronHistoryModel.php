<?php

namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;

class CronHistoryModel extends AbstractPathfinderModel {

    protected $table = 'cron_history';

    protected $fieldConf = [
        'cronId' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\CronModel'
        ],
        'lastExecDuration' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'cpuTime' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'ioTime' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => null
        ],
        'lastExecMemPeak' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
            'default' => null
        ],
        'created' => [
            'type' => Schema::DT_TIMESTAMP,
            'default' => Schema::DF_CURRENT_TIMESTAMP,
            'index' => true
        ]
    ];
}
