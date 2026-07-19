<?php
/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 26.02.15
 * Time: 21:12
 */

namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;
use Exodus4D\Pathfinder\Controller\Api\Rest\Route;
use Exodus4D\Pathfinder\Lib\Logging;
use Exodus4D\Pathfinder\Exception;

class ConnectionModel extends AbstractMapTrackingModel {

    /**
     * @var string
     */
    protected $table = 'connection';

    /**
     * @var array
     */
    protected $fieldConf = [
        'active' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 1,
            'index' => true
        ],
        'mapId' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\MapModel',
            'constraint' => [
                [
                    'table' => 'map',
                    'on-delete' => 'CASCADE'
                ]
            ]
        ],
        'source' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\SystemModel',
            'constraint' => [
                [
                    'table' => 'system',
                    'on-delete' => 'CASCADE'
                ]
            ],
            'activity-log' => true
        ],
        'target' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\SystemModel',
            'constraint' => [
                [
                    'table' => 'system',
                    'on-delete' => 'CASCADE'
                ]
            ],
            'activity-log' =>  true
        ],
        'scope' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => '',
            'activity-log' => true
        ],
        'type' => [
            'type' => self::DT_JSON,
            'activity-log' => true
        ],
        'sourceEndpointType' => [
            'type' => self::DT_JSON,
            'activity-log' => true
        ],
        'targetEndpointType' => [
            'type' => self::DT_JSON,
            'activity-log' => true
        ],
        'eolUpdated' => [
            'type' => Schema::DT_TIMESTAMP,
            'default' => null
        ],
        'signatures' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\SystemSignatureModel', 'connectionId']
        ],
        'connectionLog' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\ConnectionLogModel', 'connectionId']
        ]
    ];

    /**
     * allowed connection types
     * @var array
     */
    protected static $connectionTypeWhitelist = [
        // base type for scopes
        'abyssal',
        'jumpbridge',
        'stargate',
        // wh mass reduction types
        'wh_fresh',
        'wh_reduced',
        'wh_critical',
        // wh jump mass types
        'wh_jump_mass_s',
        'wh_jump_mass_m',
        'wh_jump_mass_l',
        'wh_jump_mass_xl',
        // other types
        'wh_eol',
        'wh_super_eol',
        'preserve_mass'
    ];

    /**
     * get connection data
     * @param bool $addSignatureData
     * @param bool $addLogData
     * @return \stdClass
     */
    public function getData($addSignatureData = false, $addLogData = false){
        $connectionData = (object) [];
        $connectionData->id             = $this->id;
        $connectionData->source         = $this->source->id;
        $connectionData->target         = $this->target->id;
        $connectionData->scope          = $this->scope;
        $connectionData->type           = (array)json_decode($this->get('type', true));
        $connectionData->updated        = strtotime($this->updated);
        $connectionData->created        = strtotime($this->created);
        $connectionData->eolUpdated     = strtotime($this->eolUpdated);

        if( !empty($endpointsData = $this->getEndpointsData()) ){
            $connectionData->endpoints = $endpointsData;
        }

        if($addSignatureData){
            if( !empty($signaturesData = $this->getSignaturesData()) ){
                $connectionData->signatures = $signaturesData;
            }
        }

        if($addLogData){
            if( !empty($logsData = $this->getLogsData()) ){
                $connectionData->logs = $logsData;
            }
        }

        return $connectionData;
    }

    /**
     * setter for connection type
     * @param $type
     * @return array
     */
    public function set_type($type){
        // remove unwanted types -> they should not be send from client
        // -> reset keys! otherwise JSON format results in object and not in array
        $type = array_values(array_intersect(array_unique((array)$type), self::$connectionTypeWhitelist));

        $hadEol         = in_array('wh_eol', (array)$this->type); // $this->type == null for new connection! (e.g. map import)
        $hadSuperEol    = in_array('wh_super_eol', (array)$this->type);

        // set EOL timestamp
        if( !in_array('wh_eol', $type) ){
            // no EOL at all -> "super EOL" can not exist without "EOL" -> drop it + clear timestamp
            $type = array_values(array_diff($type, ['wh_super_eol']));
            $this->eolUpdated = null;
        }else{
            // connection EOL status change
            if( !$hadEol ){
                $this->touch('eolUpdated');
            }

            // newly "super EOL" (collapse within ~1h) -> backdate "eolUpdated" so that at most
            // EXPIRE_CONNECTIONS_SUPER_EOL remains until the existing "deleteEolConnections" cron removes it.
            // This unifies both paths (manual toggle & auto-conversion) to "delete ~1h after going super".
            if( in_array('wh_super_eol', $type) && !$hadSuperEol ){
                $f3 = self::getF3();
                $eolExpire   = (int)$f3->get('PATHFINDER.CACHE.EXPIRE_CONNECTIONS_EOL');
                $superWindow = (int)$f3->get('PATHFINDER.CACHE.EXPIRE_CONNECTIONS_SUPER_EOL');
                if($eolExpire > 0 && $superWindow > 0 && $superWindow < $eolExpire){
                    $latestAllowed = time() - ($eolExpire - $superWindow);
                    $currentEol = $this->eolUpdated ? strtotime($this->eolUpdated) : time();
                    if($currentEol > $latestAllowed){
                        $this->eolUpdated = date('Y-m-d H:i:s', $latestAllowed);
                    }
                }
            }
        }

        return $type;
    }

    /**
     * reset the "super EOL" countdown timer
     * -> re-stamp "eolUpdated" so that ~EXPIRE_CONNECTIONS_SUPER_EOL remains again (fresh ~1h countdown)
     * -> only valid while the connection is currently "super EOL"
     * @return bool whether the timer was reset
     */
    public function resetSuperEol() : bool {
        if( !in_array('wh_super_eol', (array)$this->type) ){
            return false;
        }

        $f3 = self::getF3();
        $eolExpire   = (int)$f3->get('PATHFINDER.CACHE.EXPIRE_CONNECTIONS_EOL');
        $superWindow = (int)$f3->get('PATHFINDER.CACHE.EXPIRE_CONNECTIONS_SUPER_EOL');
        if($eolExpire > 0 && $superWindow > 0 && $superWindow < $eolExpire){
            $this->eolUpdated = date('Y-m-d H:i:s', time() - ($eolExpire - $superWindow));
            return true;
        }
        return false;
    }

    /**
     * setter for endpoints data (data for source/target endpoint)
     * @param $endpointsData
     */
    public function set_endpoints($endpointsData){
        if(!empty($endpointData = (array)$endpointsData['source'])){
            $this->setEndpointData('source', $endpointData);
        }
        if(!empty($endpointData = (array)$endpointsData['target'])){
            $this->setEndpointData('target', $endpointData);
        }
    }

    /**
     * set connection endpoint related data
     * @param string $label (source||target)
     * @param array $endpointData
     */
    public function setEndpointData(string $label, array $endpointData = []){
        if($this->exists($field = $label . 'EndpointType')){
            $types = empty($types = (array)$endpointData['types']) ? null : $types;
            if($this->$field != $types){
                $this->$field = $types;
            }
        }
    }

    /**
     * check object for model access
     * @param CharacterModel $characterModel
     * @return bool
     */
    public function hasAccess(CharacterModel $characterModel) : bool {
        $access = false;
        if( !$this->dry() ){
            $access = $this->mapId->hasAccess($characterModel);
        }
        return $access;
    }

    /**
     * set default connection scope + type by search route between endpoints
     * @throws \Exception
     */
    public function setAutoScopeAndType(){
        if(
            is_object($this->source) &&
            is_object($this->target)
        ){
            if(
                $this->source->isAbyss() ||
                $this->target->isAbyss()
            ){
                $this->scope = 'abyssal';
                $this->type = ['abyssal'];
            }elseif(
                $this->source->isKspace() &&
                $this->target->isKspace() &&
                (new Route())->searchRoute($this->source->systemId, $this->target->systemId, 1)['routePossible']
            ){
                $this->scope = 'stargate';
                $this->type = ['stargate'];
            }else{
                $this->scope = 'wh';
                $this->type = ['wh_fresh'];
            }
        }
    }

    /**
     * check whether this connection is a wormhole or not
     * @return bool
     */
    public function isWormhole() : bool {
        return ($this->scope === 'wh');
    }

    /**
     * check whether this model is valid or not
     * @return bool
     * @throws Exception\DatabaseException
     */
    public function isValid() : bool {
        if($valid = parent::isValid()){
            // check if source/target system are not equal
            // check if source/target belong to same map
            if(
                is_object($this->source) &&
                is_object($this->target) &&
                $this->get('source', true) === $this->get('target', true) ||
                $this->source->get('mapId', true) !== $this->target->get('mapId', true)
            ){
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Event "Hook" function
     * can be overwritten
     * return false will stop any further action
     * @param \Exodus4D\Pathfinder\Model\AbstractModel $self
     * @param $pkeys
     * @return bool
     * @throws Exception\DatabaseException
     * @throws \Exception
     */
    public function beforeInsertEvent($self, $pkeys) : bool {
        // check for "default" connection type and add them if missing
        // -> get() with "true" returns RAW data! important for JSON table column check!
        $types = (array)json_decode($this->get('type', true));
        if(
            !$this->scope ||
            empty($types)
        ){
            $this->setAutoScopeAndType();
        }

        return $this->isValid() ? parent::beforeInsertEvent($self, $pkeys) : false;
    }

    /**
     * Event "Hook" function
     * return false will stop any further action
     * @param self $self
     * @param $pkeys
     */
    public function afterInsertEvent($self, $pkeys){
        $self->clearCacheData();
        $self->logActivity('connectionCreate');
    }

    /**
     * Event "Hook" function
     * return false will stop any further action
     * @param self $self
     * @param $pkeys
     */
    public function afterUpdateEvent($self, $pkeys){
        $self->clearCacheData();
        $self->logActivity('connectionUpdate');
    }

    /**
     * Event "Hook" function
     * can be overwritten
     * @param self $self
     * @param $pkeys
     */
    public function afterEraseEvent($self, $pkeys){
        $self->clearCacheData();
        $self->logActivity('connectionDelete');
    }

    /**
     * @param string $action
     * @return Logging\LogInterface
     * @throws Exception\ConfigException
     */
    public function newLog(string $action = '') : Logging\LogInterface {
        return $this->getMap()->newLog($action)->setTempData($this->getLogObjectData());
    }

    /**
     * @return MapModel
     */
    public function getMap() : MapModel {
        return $this->get('mapId');
    }

    /**
     * delete a connection
     * @param CharacterModel $characterModel
     * @return bool
     */
    public function delete(CharacterModel $characterModel) : bool {
        return ($this->valid() && $this->hasAccess($characterModel)) ? $this->erase() : false;
    }

    /**
     * get object relevant data for model log
     * @return array
     */
    public function getLogObjectData() : array {
        return [
            'objId' => $this->_id,
            'objName' => $this->scope
        ];
    }

    /**
     * see parent
     */
    public function clearCacheData(){
        $this->mapId->clearCacheData();
    }

    /**
     * get all signatures that are connected with this connection
     * @return array|mixed
     */
    public function getSignatures(){
        $signatures = [];
        $this->filter('signatures', [
            'active = :active',
            ':active' => 1
        ]);

        if($this->signatures){
            $signatures = $this->signatures;
        }

        return $signatures;
    }

    /**
     * get all jump logs that are connected with this connection
     * @return array|mixed
     */
    public function getLogs(){
        $logs = [];

        if($this->connectionLog){
            $logs = $this->connectionLog;
        }

        return $logs;
    }

    /**
     * get endpoint data for $type (source || target)
     * @param string $type
     * @return array
     */
    protected function getEndpointData(string $type) : array {
        $endpointData = [];

        if($this->exists($field = $type . 'EndpointType') && !empty($types = (array)$this->$field)){
            $endpointData['types'] = $types;
        }

        return $endpointData;
    }

    /**
     * get all endpoint data for this connection
     * @return array
     */
    protected function getEndpointsData() : array {
        $endpointsData = [];

        if(!empty($endpointData = $this->getEndpointData('source'))){
            $endpointsData['source'] = $endpointData;
        }
        if(!empty($endpointData = $this->getEndpointData('target'))){
            $endpointsData['target'] = $endpointData;
        }

        return $endpointsData;
    }

    /**
     * get all signature data linked to this connection
     * @return array
     */
    public function getSignaturesData() : array {
        $signaturesData = [];
        $signatures = $this->getSignatures();

        foreach($signatures as $signature){
            $signaturesData[] = $signature->getData();
        }

        return $signaturesData;
    }

    /**
     * get all connection log data linked to this connection
     * @return array
     */
    public function getLogsData() : array {
        $logsData = [];
        $logs = $this->getLogs();

        foreach($logs as $log){
            $logsData[] = $log->getData();
        }

        return $logsData;
    }

    /**
     * get blank connectionLog model
     * @return ConnectionLogModel
     * @throws \Exception
     */
    public function getNewLog() : ConnectionLogModel {
        /**
         * @var $log ConnectionLogModel
         */
        $log = self::getNew('ConnectionLogModel');
        $log->connectionId = $this;
        return $log;
    }

    /**
     * Log mass for this connection (idempotent: same character + same jump = one record).
     * Goal: one jump recorded once regardless of browser/standalone path.
     * - Duplicate key on insert is treated as success (no-op), not error (no 500).
     * - Connection selection (searchConnection / parallel-hole policy) is unchanged.
     *
     * dedupeKey는 '점프 사건'을 식별해야 한다 (경로가 아니라).
     * 구 키(connectionId_characterId_from_to)에는 시간 성분이 없어 같은 웜홀을 같은
     * 방향으로 두 번째 통과하는 순간부터 UNIQUE 충돌로 영구히 조용히 버려졌다.
     * → 웜홀 롤링(질량 붕괴용 반복 통행)에서 누적 질량이 실제보다 과소 집계됨.
     * characterLog.updated는 값이 실제로 바뀔 때만 touch되므로(AbstractModel::set)
     * '현재 시스템에 진입한 시각' = 점프 사건의 안정적 식별자다. 같은 점프를 웹/데몬이
     * 동시에 처리하면 같은 row를 읽어 키가 일치(중복 차단), 재통행은 새 시각이라 기록된다.
     *
     * @param CharacterLogModel $characterLog
     * @param int $fromSystemId source system id (jump direction)
     * @param int $targetSystemId target system id (jump direction)
     * @return ConnectionModel
     */
    public function logMass(CharacterLogModel $characterLog, int $fromSystemId, int $targetSystemId) : self {
        if( $characterLog->dry() ){
            return $this;
        }

        $connectionId = (int)$this->_id;
        $characterId  = (int)$characterLog->characterId->_id;

        // 점프 사건 시각. 파싱 실패 시 time() 폴백 — 중복 1건이 누락 1건보다 낫다
        // (질량 집계는 과소 집계가 치명적, 과대 집계는 눈에 보임)
        $jumpAt = !empty($characterLog->updated) ? strtotime((string)$characterLog->updated) : 0;
        if(!$jumpAt){
            $jumpAt = time();
        }

        $dedupeKey    = $connectionId . '_' . $characterId . '_' . $fromSystemId . '_' . $targetSystemId . '_' . $jumpAt;

        $log = $this->getNewLog();
        $log->shipTypeId     = $characterLog->shipTypeId;
        $log->shipTypeName   = $characterLog->shipTypeName;
        $log->shipMass       = $characterLog->shipMass;
        $log->characterId    = $characterId;
        $log->characterName  = $characterLog->characterId->name;
        $log->sourceSystemId = $fromSystemId;
        $log->targetSystemId = $targetSystemId;
        $log->dedupeKey      = $dedupeKey;

        try {
            $log->save();
            \Exodus4D\Pathfinder\Lib\Metrics::counter('pf_connection_mass_log_total', ['result' => 'logged']);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $msg  = (string)$e->getMessage();
            $isDuplicate = ( (int)$code === 1062 || (string)$code === '23000' || (int)$code === 23000 )
                || stripos($msg, 'Duplicate entry') !== false
                || stripos($msg, 'duplicate key') !== false;
            if ( $isDuplicate ) {
                // 정상 동작: 같은 점프를 웹/데몬이 동시 처리한 경우.
                // 이 카운터가 logged 대비 비정상적으로 높으면 키 설계를 다시 봐야 한다
                \Exodus4D\Pathfinder\Lib\Metrics::counter('pf_connection_mass_log_total', ['result' => 'deduped']);
                return $this;
            }
            \Exodus4D\Pathfinder\Lib\Metrics::counter('pf_connection_mass_log_total', ['result' => 'error']);
            throw $e;
        }

        return $this;
    }

    /**
     * overwrites parent
     * @param null $db
     * @param null $table
     * @param null $fields
     * @return bool
     * @throws \Exception
     */
    public static function setup($db = null, $table = null, $fields = null){
        if($status = parent::setup($db, $table, $fields)){
            $status = parent::setMultiColumnIndex(['source', 'target', 'scope']);
        }
        return $status;
    }
} 