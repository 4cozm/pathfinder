<?php
/**
 * Created by PhpStorm.
 * User: exodus4d
 * Date: 11.04.15
 * Time: 15:20
 */
namespace Exodus4D\Pathfinder\Model\Pathfinder;

use Exodus4D\Pathfinder\Controller\Ccp\Sso as Sso;
use Exodus4D\Pathfinder\Controller\Api\User as User;
use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Model\Universe;
use DB\SQL\Schema;

/**
 * @property int $_id
 * @property string $name
 * @property string $ownerHash
 * @property mixed $esiAccessToken
 * @property mixed $esiAccessTokenExpires
 * @property string $esiRefreshToken
 * @property mixed $esiScopes
 * @property int|null $corporationId
 * @property int|null $allianceId
 * @property int $cloneLocationId
 * @property string $cloneLocationType
 * @property mixed $kicked
 * @property mixed $banned
 * @property bool $shared
 * @property bool $logLocation
 * @property bool $selectLocation
 * @property mixed $lastLogin
 * @property bool $active
 * @property mixed $authStatus
 * @property \Exodus4D\Pathfinder\Model\Pathfinder\RoleModel $roleId
 * @property \Exodus4D\Pathfinder\Model\Pathfinder\UserCharacterModel|null $userCharacter
 * @property \Exodus4D\Pathfinder\Model\Pathfinder\CharacterLogModel|null $characterLog
 * @property \DB\CortexCollection|null $characterMaps
 * @property \DB\CortexCollection|null $characterAuthentications
 * @property mixed $updated
 * @method mixed get(string $key, bool $raw = false)
 * @method bool dry()
 * @method mixed rel(string $key)
 * @method bool valid()
 * @method void copyfrom(array $var, array $keys = null)
 * @method void erase()
 * @method void clear(string $key)
 * @method self filter(string $key, $cond = null, array $options = null)
 * @method \DB\CortexCollection find(array $filter = null, array $options = null)
 */
class CharacterModel extends AbstractPathfinderModel {

    /**
     * @var string
     */
    protected $table                    = 'character';

    /**
     * cache key prefix for getData(); result WITH log data
     */
    const DATA_CACHE_KEY_LOG            = 'LOG';

    /**
     * log message for character access
     */
    const LOG_ACCESS                    = 'charId: [%20s], status: %s, charName: %s';

    /**
     * max count of historic character logs
     * -> this includes logs where just e.g. shipTypeId has changed but no systemId change!
     */
    const MAX_LOG_HISTORY_DATA          = 10;

    /**
     * TTL for historic character logs
     */
    const TTL_LOG_HISTORY               = 60 * 60 * 22;

    /**
     * cache key prefix historic character logs
     */
    const DATA_CACHE_KEY_LOG_HISTORY    = 'LOG_HISTORY';

    /**
     * ESI /characters/{id}/location/ is server-side cached for ~5s.
     * Re-polling within this window can only return the same (or an older, still-cached) snapshot,
     * so updateLog() skips the poll entirely if the log is fresher than this.
     */
    const ESI_LOCATION_CACHE_TTL        = 5;

    /**
     * fallback for PATHFINDER.CACHE.CHARACTER_LOG_INACTIVE (seconds)
     * -> keep in sync with Cron\CharacterUpdate::CHARACTER_LOG_INACTIVE
     */
    const CHARACTER_LOG_INACTIVE        = 180;

    /**
     * character authorization status
     * @var array
     */
    const AUTHORIZATION_STATUS = [
        'OK'            => true,                                        // success
        'UNKNOWN'       => 'error',                                     // general authorization error
        'CHARACTER'     => 'failed to match character whitelist',
        'CORPORATION'   => 'failed to match corporation whitelist',
        'ALLIANCE'      => 'failed to match alliance whitelist',
        'KICKED'        => 'character is kicked',
        'BANNED'        => 'character is banned'
    ];
    
    /**
     * enables change for "kicked" column
     * -> see kick();
     * @var bool
     */
    private $allowKickChange = false;

    /**
     * enables change for "banned" column
     * -> see ban();
     * @var bool
     */
    private $allowBanChange = false;

    /**
     * @var array
     */
    protected $fieldConf = [
        'lastLogin' => [
            'type' => Schema::DT_TIMESTAMP,
            'index' => true
        ],
        'active' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 1,
            'index' => true
        ],
        'name' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => ''
        ],
        'ownerHash' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => ''
        ],
        'esiAccessToken' => [
            'type' => Schema::DT_TEXT
        ],
        'esiAccessTokenExpires' => [
            'type' => Schema::DT_TIMESTAMP,
            'default' => Schema::DF_CURRENT_TIMESTAMP,
            'index' => true
        ],
        'esiRefreshToken' => [
            'type' => Schema::DT_VARCHAR256
        ],
        'esiScopes' => [
            'type' => Schema::DT_JSON
        ],
        'corporationId' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\CorporationModel',
            'constraint' => [
                [
                    'table' => 'corporation',
                    'on-delete' => 'SET NULL'
                ]
            ]
        ],
        'allianceId' => [
            'type' => Schema::DT_INT,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\AllianceModel',
            'constraint' => [
                [
                    'table' => 'alliance',
                    'on-delete' => 'SET NULL'
                ]
            ]
        ],
        'roleId' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'default' => 1,
            'index' => true,
            'belongs-to-one' => 'Exodus4D\Pathfinder\Model\Pathfinder\RoleModel',
            'constraint' => [
                [
                    'table' => 'role',
                    'on-delete' => 'CASCADE'
                ]
            ],
        ],
        'cloneLocationId' => [
            'type' => Schema::DT_BIGINT,
            'index' => true,
            'activity-log' =>  true
        ],
        'cloneLocationType' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => ''
        ],
        'kicked' => [
            'type' => Schema::DT_TIMESTAMP,
            'index' => true
        ],
        'banned' => [
            'type' => Schema::DT_TIMESTAMP,
            'index' => true
        ],
        'shared' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 0
        ],
        'logLocation' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 1
        ],
        'selectLocation' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 0
        ],
        'securityStatus' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => false,
            'default' => 0
        ],
        'userCharacter' => [
            'has-one' => ['Exodus4D\Pathfinder\Model\Pathfinder\UserCharacterModel', 'characterId']
        ],
        'characterLog' => [
            'has-one' => ['Exodus4D\Pathfinder\Model\Pathfinder\CharacterLogModel', 'characterId']
        ],
        'characterMaps' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\CharacterMapModel', 'characterId']
        ],
        'characterAuthentications' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\CharacterAuthenticationModel', 'characterId']
        ],
        'characterRights' => [
            'has-many' => ['Exodus4D\Pathfinder\Model\Pathfinder\CharacterRightModel', 'characterId']
        ],
        'adminMemo' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
            'default' => ''
        ]
    ];

    /**
     * get character data
     * @param bool $addLogData
     * @param bool $addLogHistoryData
     * @return mixed|object|null
     * @throws \Exception
     */
    public function getData($addLogData = false, $addLogHistoryData = false){
        // check for cached data
        if(is_null($characterData = $this->getCacheData())){
            // no cached character data found

            $characterData                      = (object) [];
            $characterData->id                  = $this->_id;
            $characterData->name                = $this->name;
            $characterData->role                = $this->roleId->getData();
            $characterData->shared              = $this->shared;
            $characterData->logLocation         = $this->logLocation;
            $characterData->selectLocation      = $this->selectLocation;
            $characterData->adminMemo           = $this->adminMemo;

            // check for corporation
            if($corporation = $this->getCorporation()){
                $characterData->corporation     = $corporation->getData();
            }

            // check for alliance
            if($alliance = $this->getAlliance()){
                $characterData->alliance        = $alliance->getData();
            }

            // max caching time for a system
            // cached date has to be cleared manually on any change
            // this applies to system, connection,... changes (+ all other dependencies)
            $this->updateCacheData($characterData);
        }

        if($addLogData){
            if(is_null($logData = $this->getCacheData(self::DATA_CACHE_KEY_LOG))){
                if($logModel = $this->getLog()){
                    $logData = $logModel->getData();
                    $this->updateCacheData($logData, self::DATA_CACHE_KEY_LOG);
                }
            }

            if($logData){
                $characterData->log             = $logData;
            }
        }

        if($addLogHistoryData && $characterData->log){
            $characterData->logHistory          = $this->getLogHistoryJumps($characterData->log->system->id);
        }

        // temp "authStatus" should not be cached
        if($this->authStatus){
            $characterData->authStatus          = $this->authStatus;
        }

        return $characterData;
    }

    /**
     * get "basic" character data
     * @return \stdClass
     * @throws \Exception
     */
    public function getBasicData() : \stdClass {
        $characterData = (object) [];
        $characterData->id = $this->_id;
        $characterData->name = $this->name;

        // check for corporation
        if($corporation = $this->getCorporation()){
            $characterData->corporation = $corporation->getData(false);
        }

        // check for alliance
        if($alliance = $this->getAlliance()){
            $characterData->alliance = $alliance->getData();
        }

        return $characterData;
    }

    /**
     * set corporation for this character
     * -> corp change resets admin actions (e.g. kick/ban)
     * @param $corporationId
     * @return mixed
     */
    public function set_corporationId($corporationId){
        $currentCorporationId = (int)$this->get('corporationId', true);

        if($currentCorporationId !== $corporationId){
             $this->resetAdminColumns();
        }

        return $corporationId;
    }

    /**
     * set unique "ownerHash" for this character
     * -> Hash will change when  character is transferred (sold)
     * @param string $ownerHash
     * @return string
     */
    public function set_ownerHash($ownerHash){
        if( $this->ownerHash !== $ownerHash ){
            if( $this->hasUserCharacter() ){
                // reset admin actions (e.g. kick/ban)
                $this->resetAdminColumns();

                // new ownerHash -> new user (reset)
                $this->userCharacter->erase();
            }

            // delete all existing login-cookie data
            $this->logout();
        }

        return $ownerHash;
    }

    /**
     * setter for "kicked" until time
     * @param $minutes
     * @return mixed|null|string
     * @throws \Exception
     */
    public function set_kicked($minutes){
        if($this->allowKickChange){
            // allowed to set/change -> reset "allowed" property
            $this->allowKickChange = false;
            $kicked = null;

            if($minutes){
                $seconds = $minutes * 60;
                $timezone = self::getF3()->get('getTimeZone')();
                $kickedUntil = new \DateTime('now', $timezone);

                // add cookie expire time
                $kickedUntil->add(new \DateInterval('PT' . $seconds . 'S'));
                $kicked = $kickedUntil->format('Y-m-d H:i:s');
            }
        }else{
            // not allowed to set/change -> keep current status
            $kicked = $this->kicked;
        }

        return $kicked;
    }

    /**
     * setter for "banned" status
     * @param $status
     * @return mixed|string|null
     * @throws \Exception
     */
    public function set_banned($status){
        if($this->allowBanChange){
            // allowed to set/change -> reset "allowed" property
            $this->allowBanChange = false;
            $banned = null;

            if($status){
                $timezone = self::getF3()->get('getTimeZone')();
                $bannedSince = new \DateTime('now', $timezone);
                $banned = $bannedSince->format('Y-m-d H:i:s');
            }
        }else{
            // not allowed to set/change -> keep current status
            $banned = $this->banned;
        }

        return $banned;
    }

    /**
     * logLocation specifies whether the current system should be tracked or not
     * @param $logLocation
     * @return bool
     */
    public function set_logLocation($logLocation){
        $logLocation = (bool)$logLocation;
        if(
            !$logLocation &&
            $logLocation !== $this->logLocation
        ){
            $this->deleteLog();
        }

        return $logLocation;
    }

    /**
     * kick character for $minutes
     * -> do NOT use $this->kicked!
     * -> this will not work (prevent abuse)
     * @param bool|int $minutes
     */
    public function kick($minutes = false){
        // enables "kicked" change for this model
        $this->allowKickChange = true;
        $this->kicked = $minutes;
    }

    /**
     * ban character
     * -> do NOT use $this->banned!
     * -> this will not work (prevent abuse)
     * @param bool|int $status
     */
    public function ban($status = false){
        // enables "banned" change for this model
        $this->allowBanChange = true;
        $this->banned = $status;
    }

    /**
     * Event "Hook" function
     * @param self $self
     * @param $pkeys
     */
    public function afterInsertEvent($self, $pkeys){
        $self->clearCacheData();
    }

    /**
     * Event "Hook" function
     * @param self $self
     * @param $pkeys
     */
    public function afterUpdateEvent($self, $pkeys){
        $self->clearCacheData();
    }

    /**
     * Event "Hook" function
     * @param self $self
     * @param $pkeys
     */
    public function afterEraseEvent($self, $pkeys){
        $self->clearCacheData();
    }

    /**
     * see parent
     */
    public function clearCacheData(){
        parent::clearCacheData();

        // clear data with "log" as well!
        parent::clearCacheDataWithPrefix(self::DATA_CACHE_KEY_LOG);
    }

    /**
     * resets some columns that could have changed by admins (e.g. kick/ban)
     */
    private function resetAdminColumns(){
        $this->kick();
        $this->ban();
    }

    /**
     * check whether this character has already a user assigned to it
     * @return bool
     */
    public function hasUserCharacter() : bool {
        return is_object($this->userCharacter);
    }

    /**
     * check whether this character has an active location log
     * @return bool
     */
    public function hasLog() : bool {
        return is_object($this->characterLog);
    }

    /**
     * check whether this character has a corporation
     * @return bool
     */
    public function hasCorporation() : bool {
        return is_object($this->corporationId);
    }

    /**
     * check whether this character has an alliance
     * @return bool
     */
    public function hasAlliance() : bool {
        return is_object($this->allianceId);
    }

    /**
     * @return UserModel|null
     */
    public function getUser() : ?UserModel {
        if (!$this->hasUserCharacter()) {
            return null;
        }
        /** @var object{userId:?\Exodus4D\Pathfinder\Model\Pathfinder\UserModel} $uc */
        $uc = $this->userCharacter;
        return $uc->userId ?? null;
    }

    /**
     * get the corporation from character
     * @return CorporationModel|null
     */
    public function getCorporation() : ?CorporationModel {
        return $this->corporationId;
    }

    /**
     * get the alliance from character
     * @return AllianceModel|null
     */
    public function getAlliance() : ?AllianceModel {
        return $this->allianceId;
    }

    /**
     * get ESI API "access_token" from OAuth
     * @return bool|string
     */
    public function getAccessToken(){
        $accessToken = false;
        $refreshToken = true;

        try{
            $timezone = self::getF3()->get('getTimeZone')();
            $now = new \DateTime('now', $timezone);

            if(
                !empty($this->esiAccessToken) &&
                !empty($this->esiAccessTokenExpires)
            ){
                $expireTime = \DateTime::createFromFormat(
                    'Y-m-d H:i:s',
                    $this->esiAccessTokenExpires,
                    $timezone
                );

                // check if token is not expired
                if($expireTime->getTimestamp() > $now->getTimestamp()){
                    // token still valid
                    $accessToken = $this->esiAccessToken;

                    // check if token should be renewed (close to expire)
                    $timeBuffer = 2 * 60;
                    $expireTime->sub(new \DateInterval('PT' . $timeBuffer . 'S'));

                    if($expireTime->getTimestamp() > $now->getTimestamp()){
                        // token NOT close to expire
                        $refreshToken = false;
                    }
                }
            }
        }catch(\Exception $e){
            self::getF3()->error(500, $e->getMessage(), $e->getTrace());
        }

        // no valid "accessToken" found OR
        // existing token is close to expire
        // -> get a fresh one by an existing "refreshToken"
        // -> in case request for new token fails (e.g. timeout) and old token is still valid -> keep old token
        if(
            $refreshToken &&
            !empty($this->esiRefreshToken)
        ){
            $ssoController = new Sso();
            $accessData =  $ssoController->refreshAccessToken($this->esiRefreshToken);

            if(isset($accessData->accessToken, $accessData->esiAccessTokenExpires, $accessData->refreshToken)){
                $this->esiAccessToken = $accessData->accessToken;
                $this->esiAccessTokenExpires = $accessData->esiAccessTokenExpires;
                $this->save();

                $accessToken = $this->esiAccessToken;
            }
        }

        return $accessToken;
    }

    /**
     * check if character  is currently kicked
     * @return bool
     */
    public function isKicked() : bool {
        $kicked = false;
        if( !is_null($this->kicked) ){
            try{
                $kickedUntil = new \DateTime();
                $kickedUntil->setTimestamp( (int)strtotime($this->kicked) );
                $now = new \DateTime();
                $kicked = ($kickedUntil > $now);
            }catch(\Exception $e){
                self::getF3()->error(500, $e->getMessage(), $e->getTrace());
            }
        }

        return $kicked;
    }

    /**
     * checks whether this character is currently logged in
     * @return bool
     */
    public function checkLoginTimer() : bool {
        $loginCheck = false;

        if( !$this->dry() && $this->lastLogin ){
            // get max login time (minutes) from config
            $maxLoginMinutes = (int)Config::getPathfinderData('timer.logged');
            if($maxLoginMinutes){
                $timezone = self::getF3()->get('getTimeZone')();
                try{
                    $now = new \DateTime('now', $timezone);
                    $logoutTime = new \DateTime($this->lastLogin, $timezone);
                    $logoutTime->add(new \DateInterval('PT' . $maxLoginMinutes . 'M'));
                    if($logoutTime->getTimestamp() > $now->getTimestamp()){
                        $loginCheck = true;
                    }
                }catch(\Exception $e){
                    self::getF3()->error(500, $e->getMessage(), $e->getTrace());
                }
            }else{
                // no "max login" timer configured -> character still logged in
                $loginCheck = true;
            }
        }

        return $loginCheck;
    }

    /**
     * checks whether this character is authorized to log in
     * -> 우선순위: SUPER/CORPORATION(ini) → character_acl(개인) → corp_acl(코퍼) → 기본 차단
     * -> 만료(expires) 미경과인 ACL만 권위를 가지며, 만료 시 다음 계층으로 fallback
     * @return string  AUTHORIZATION_STATUS 키 (OK / CHARACTER / CORPORATION / KICKED / BANNED ...)
     * @throws \Exception
     */
    public function isAuthorized() : string {
        $authStatus = 'UNKNOWN';

        // check whether character is banned or temp kicked
        if(is_null($this->banned)){
            if( !$this->isKicked() ){
                // SUPER / CORPORATION (pathfinder.ini [PATHFINDER.ROLES]) → 항상 로그인 허용 (부트스트랩 어드민)
                $role = $this->getRole();
                if($role && in_array($role->name, ['SUPER', 'CORPORATION'], true)){
                    return 'OK';
                }

                // [우선순위] 개인(character_acl) → 코퍼(corp_acl) → 기본 차단. (docs/CORP_ACL_DESIGN.md)
                // 개인 entry가 존재하고 미만료면 corp을 완전 오버라이드(허용/차단). 만료면 corp으로 fallback.
                $characterAcl = CharacterAclModel::getByCharacterId((int)$this->_id);
                if($characterAcl && !$characterAcl->isExpired()){
                    return $characterAcl->canLogin ? 'OK' : 'CHARACTER';
                }

                // 코퍼 계층
                if($this->hasCorporation()){
                    $corpAcl = CorpAclModel::getByCorporationId((int)$this->get('corporationId', true));
                    if($corpAcl && !$corpAcl->isExpired()){
                        return $corpAcl->canLogin ? 'OK' : 'CORPORATION';
                    }
                }

                // 기본 차단: 어떤 ACL에도 등록되지 않음.
                // (session_sharing 로그인 바이패스는 제거됨 — 모든 캐릭터는 명시적 승인이 있어야 한다.
                //  개별 승인이 필요하면 '개인 관리'(character_acl)에 등록한다.)
                $authStatus = 'CORPORATION';
            }else{
                $authStatus = 'KICKED';
            }
        }else{
            $authStatus = 'BANNED';
        }

        return $authStatus;
    }

    /**
     * 이 캐릭터가 SUPER(어드민)인지. pathfinder.ini [PATHFINDER.ROLES] 를 실시간 조회(getRole)하므로
     * DB 저장값(roleId)이 아닌 현재 설정 기준이다. isAuthorized / hasRight 가 동일 소스를 쓰도록 공개.
     * @return bool
     * @throws \Exception
     */
    public function isSuperAdmin() : bool {
        $role = $this->getRole();
        return $role && $role->name === 'SUPER';
    }

    /**
     * get Pathfinder role for character
     * @return RoleModel
     * @throws \Exception
     */
    protected function getRole() : RoleModel {
        $role = null;

        // check config files for hardcoded character roles
        if(self::getF3()->exists('PATHFINDER.ROLES.CHARACTER', $globalAdminData)){
            foreach((array)$globalAdminData as $adminData){
                if((int)$adminData['ID'] === (int)$this->_id){
                    switch($adminData['ROLE']){
                        case 'SUPER':
                            $role = RoleModel::getAdminRole();
                            break;
                        case 'CORPORATION':
                            $role = RoleModel::getCorporationManagerRole();
                            break;
                    }
                    break;
                }
            }
        }

        /*
        // SECURITY RISK: EVE 게임 내 직책(Director, Personnel Manager 등)이 있다고 해서 자동으로 어드민 권한을 부여하지 않습니다.
        // 이 로직이 활성화되면 동맹 코퍼레이션의 CEO가 접속 시 우리 사이트의 어드민 페이지에 접근할 수 있는 위험이 있습니다.
        // 어드민은 오직 환경 설정 파일(pathfinder.ini) 또는 DB를 통한 수동 지정으로만 가능해야 합니다.

        // check in-game roles
        if(
            is_null($role) &&
            !empty($rolesData = $this->requestRoles()) &&
            !empty($roles = $rolesData['roles'])
        ){
            // roles that grant admin access for this character
            $adminRoles = array_intersect(CorporationModel::ADMIN_ROLES, $roles);
            if(!empty($adminRoles)){
                $role = RoleModel::getCorporationManagerRole();
            }
        }
        */

        // default role
        if(is_null($role)){
            $role = RoleModel::getDefaultRole();
        }

        return $role;
    }

    /**
     * get all character roles grouped by 'role type'
     * -> 'role types' are 'roles', 'rolesAtBase', 'rolesAtHq', 'rolesAtOther'
     * @return array
     */
    protected function requestRoles() : array {
        $rolesData = [];
        $response = self::getF3()->ccpClient()->send('getCharacterRoles', $this->_id, $this->getAccessToken());
        if(!empty($response) && !isset($response['error'])){
            $rolesData = $response;
        }
        return $rolesData;
    }

    /**
     * check whether this char has accepted all "basic" api scopes
     * @return bool
     */
    public function hasBasicScopes() : bool {
        return empty(array_diff(Sso::getScopesByAuthType(), $this->esiScopes));
    }

    /**
     * check whether this char has accepted all admin api scopes
     * @return bool
     */
    public function hasAdminScopes() : bool {
        return empty(array_diff(Sso::getScopesByAuthType('admin'), $this->esiScopes));
    }

    /**
     * update clone data
     */
    public function updateCloneData(){
        if($accessToken = $this->getAccessToken()){
            $clonesData = self::getF3()->ccpClient()->send('getCharacterClones', $this->_id, $accessToken);
            if(!isset($clonesData['error'])){
                if(!empty($homeLocationData = $clonesData['home']['location'])){
                    // clone home location data
                    $this->cloneLocationId = (int)$homeLocationData['id'];
                    $this->cloneLocationType = (string)$homeLocationData['type'];
                }
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function updateRoleData(){
        $this->roleId = $this->getRole();
    }

    /**
     * get online status data from ESI
     * @param string $accessToken
     * @return array
     */
    protected function getOnlineData(string $accessToken) : array {
        return self::getF3()->ccpClient()->send('getCharacterOnline', $this->_id, $accessToken);
    }

    /**
     * check online state from ESI
     * @param string $accessToken
     * @return bool
     */
    public function isOnline(string $accessToken) : bool {
        $isOnline = false;
        $onlineData = $this->getOnlineData($accessToken);

        if($onlineData['online'] === true){
            $isOnline = true;
        }

        return $isOnline;
    }

    /**
     * update character log (active system, ...)
     * -> API request for character log data
     *
     * Location updates are triggered concurrently by web clients (updateUserData) and the
     * standalone daemon (updateStandaloneTrackedLogs). ESI /location/ is cached (~5s) and a
     * slow concurrent updater can persist its stale response *after* a fresher one was saved.
     * That regresses the log to a previously visited system; the next poll then re-interprets
     * the regression as a new jump and wires new wormholes to the wrong source system.
     * Two guards prevent this:
     *   1. freshness gate — skip the poll if the log is fresher than the ESI cache window
     *   2. per-character DB named lock — a second updater skips instead of racing the first
     *
     * @param array $additionalOptions (optional) request options for cURL request
     * @return CharacterModel
     * @throws \Exception
     */
    public function updateLog($additionalOptions = []) : self {
        // guards only apply when an ESI poll would actually happen
        // (otherwise updateLogUnsafe() must still run its deleteLog cleanup paths)
        if(
            $this->logLocation &&
            $this->hasBasicScopes()
        ){
            // 1) freshness gate
            if(
                ($currentLog = $this->getLog()) &&
                !empty($currentLog->updated) &&
                (time() - strtotime($currentLog->updated)) < self::ESI_LOCATION_CACHE_TTL
            ){
                return $this;
            }

            // 2) per-character mutex (MySQL/MariaDB named lock, non-blocking)
            //    -> lock is server-wide, works across php-fpm workers and the standalone daemon
            $lockDb = null;
            $lockName = 'pf_charlog_' . $this->_id;
            try{
                if(($dbPool = self::getF3()->get('DB')) && is_object($dbPool) && method_exists($dbPool, 'getDB')){
                    $lockDb = $dbPool->getDB('PF');
                }
            }catch(\Throwable $e){
                $lockDb = null;
            }

            if($lockDb){
                $lockRes = $lockDb->exec('SELECT GET_LOCK(:lockName, 0) AS acquired', [':lockName' => $lockName]);
                if(empty($lockRes[0]['acquired'])){
                    // another process is updating this character right now
                    // -> its data is at least as fresh as ours would be
                    return $this;
                }

                try{
                    return $this->updateLogUnsafe($additionalOptions);
                }finally{
                    $lockDb->exec('SELECT RELEASE_LOCK(:lockName)', [':lockName' => $lockName]);
                }
            }
        }

        return $this->updateLogUnsafe($additionalOptions);
    }

    /**
     * unguarded log update. Do not call directly — use updateLog()
     * @param array $additionalOptions (optional) request options for cURL request
     * @return CharacterModel
     * @throws \Exception
     */
    protected function updateLogUnsafe($additionalOptions = []) : self {
        $deleteLog = false;
        $invalidResponse = false;

        //check if log update is enabled for this character
        // check if character has accepted all scopes. (This fkt is called by cron as well)
        if(
            $this->logLocation &&
            $this->hasBasicScopes()
        ){
            // Backpressure check: skip update if systemic pressure is high
            // Deterministic sampling ensures fairness (same character consistently throttled in a window)
            if(
                \Exodus4D\Pathfinder\Lib\Api\BackpressureManager::instance()->shouldThrottle($this->_id) &&
                ($characterLog = $this->getLog())
            ){
                // Even with backpressure, we allow updates if it's been a long time (e.g. 5 minutes)
                // but if it was recent (within 60s), we skip to save ESI calls and workers
                $now = new \DateTime();
                $lastUpdate = new \DateTime($characterLog->updated);
                if($now->getTimestamp() - $lastUpdate->getTimestamp() < 60){
                    return $this;
                }
            }
            // Try to pull data from API
            if($accessToken = $this->getAccessToken()){
                if($this->isOnline($accessToken)){
                    $locationData = self::getF3()->ccpClient()->send('getCharacterLocation', $this->_id, $accessToken);

                    if(!empty($locationData['system']['id'])){
                        // character is currently in-game

                        // get current $characterLog or get new -------------------------------------------------------
                        if(!$characterLog = $this->getLog()){
                            // create new log
                            $characterLog = $this->rel('characterLog');

                            // Double check DB (direct hit) to avoid race conditions (Unique constraint violation)
                            // if getLog() (which uses cache) has a cache miss
                            $characterLog->load(['characterId = ?', $this->_id]);

                            if($characterLog->dry()){
                                // truly no log found -> reset to clean state for new insert
                                $characterLog->reset();
                            }
                        }

                        // zombie log guard ----------------------------------------------------------------------
                        // an existing log this old means the cleanup cron missed it (e.g. cron/worker died
                        // while the character was in-game offline) -> session continuity is broken.
                        // erase it (cascades to LOG_HISTORY cleanup) and start over with a fresh log,
                        // so the stale system -> current system change is not misread as a direct jump.
                        // threshold: 2x cleanup limit, because healthy rows can legitimately age up to
                        // ~CHARACTER_LOG_INACTIVE + cron latency before deleteLogData() touches them
                        if(!$characterLog->dry() && !empty($characterLog->updated)){
                            $logAge = time() - strtotime($characterLog->updated);
                            if($logAge > 2 * self::getLogInactiveTime()){
                                $characterLog->erase();
                                $characterLog = $this->rel('characterLog');
                                $characterLog->reset();
                            }
                        }

                        // get current log data and modify on change
                        $logData = $characterLog::toArray($characterLog->getData());

                        // check system and station data for changes --------------------------------------------------

                        // IDs for "systemId", "stationId" that require more data
                        $lookupUniverseIds = [];
                        if(
                            empty($logData['system']['name']) ||
                            $logData['system']['id'] !== $locationData['system']['id']
                        ){
                            // system changed -> request "system name" for current system
                            $lookupUniverseIds[] = $locationData['system']['id'];
                        }

                        $logData = array_replace_recursive($logData, $locationData);

                        // get "more" data for systemId ---------------------------------------------------------------
                        if(!empty($lookupUniverseIds)){
                            // get "more" information for some Ids (e.g. name)
                            $universeData = self::getF3()->ccpClient()->send('getUniverseNames', $lookupUniverseIds);

                            if(!empty($universeData) && !isset($universeData['error'])){
                                // We expect max ONE system AND/OR station data, not an array of e.g. systems
                                if(!empty($universeData['system'])){
                                    $universeData['system'] = reset($universeData['system']);
                                }

                                $logData = array_replace_recursive($logData, $universeData);
                            }else{
                                // this is important! universe data is a MUST HAVE!
                                $deleteLog = true;
                            }
                        }

                        // check station data for changes -------------------------------------------------------------
                        if(!$deleteLog){
                            // IDs for "stationId" that require more data
                            $lookupStationId = 0;
                            if(!empty($locationData['station']['id'])){
                                if(
                                    empty($logData['station']['name']) ||
                                    $logData['station']['id']  !== $locationData['station']['id']
                                ){
                                    // station changed -> request station data
                                    $lookupStationId = $locationData['station']['id'];
                                }
                            }else{
                                unset($logData['station']);
                            }

                            // get "more" data for stationId
                            if($lookupStationId > 0){
                                /** @var object $stationModel */
                                $stationModel = Universe\AbstractUniverseModel::getNew('StationModel');
                                $stationModel->loadById($lookupStationId, $accessToken, $additionalOptions);
                                if($stationModel->valid()){
                                    $stationData['station'] = $stationModel::toArray($stationModel->getData());
                                    $logData = array_replace_recursive($logData, $stationData);
                                }else{
                                    unset($logData['station']);
                                }
                            }
                        }

                        // check structure data for changes -----------------------------------------------------------
                        if(!$deleteLog){
                            // IDs for "structureId" that require more data
                            $lookupStructureId = 0;
                            if(!empty($locationData['structure']['id'])){
                                if(
                                    empty($logData['structure']['name']) ||
                                    $logData['structure']['id']  !== $locationData['structure']['id']
                                ){
                                    // structure changed -> request structure data
                                    $lookupStructureId = $locationData['structure']['id'];
                                }
                            }else{
                                unset($logData['structure']);
                            }

                            // get "more" data for structureId
                            if($lookupStructureId > 0){
                                /** @var object $structureModel */
                                $structureModel = Universe\AbstractUniverseModel::getNew('StructureModel');
                                $structureModel->loadById($lookupStructureId, $accessToken, $additionalOptions);
                                if($structureModel->valid()){
                                    $structureData['structure'] = $structureModel::toArray($structureModel->getData());
                                    $logData = array_replace_recursive($logData, $structureData);
                                }else{
                                    unset($logData['structure']);
                                }
                            }
                        }

                        // check ship data for changes ----------------------------------------------------------------
                        if(!$deleteLog){
                            $shipData = self::getF3()->ccpClient()->send('getCharacterShip', $this->_id, $accessToken);

                            // IDs for "shipTypeId" that require more data
                            $lookupShipTypeId = 0;
                            if(!empty($shipData['ship']['typeId'])){
                                if(
                                    empty($logData['ship']['typeName']) ||
                                    $logData['ship']['typeId'] !== $shipData['ship']['typeId']
                                ){
                                    // ship changed -> request "station name" for current station
                                    $lookupShipTypeId = $shipData['ship']['typeId'];
                                }

                                // "shipName"/"shipId" could have changed...
                                $logData = array_replace_recursive($logData, $shipData);
                            }else{
                                // ship data should never be empty -> keep current one
                                //unset($logData['ship']);
                                $invalidResponse = true;
                            }

                            // get "more" data for shipTypeId
                            if($lookupShipTypeId > 0){
                                /** @var object $typeModel */
                                $typeModel = Universe\AbstractUniverseModel::getNew('TypeModel');
                                $typeModel->loadById($lookupShipTypeId, '', $additionalOptions);
                                if(!$typeModel->dry()){
                                    $shipData['ship'] = (array)$typeModel->getShipData();
                                    $logData = array_replace_recursive($logData, $shipData);
                                }else{
                                    // this is important! ship data is a MUST HAVE!
                                    $deleteLog = true;
                                }
                            }
                        }

                        if(!$deleteLog){
                            // mark log as "updated" even if no changes were made
                            if(($additionalOptions['markUpdated'] ?? false) === true){
                                $characterLog->touch('updated');
                            }

                            $characterLog->setData($logData);
                            $characterLog->characterId = $this->_id;
                            $characterLog->save();

                            $this->characterLog = $characterLog;
                        }
                    }else{
                        // systemId should always exists
                        $invalidResponse = true;
                    }
                }else{
                    // user is in-game offline
                    $deleteLog = true;
                }
            }else{
                // access token request failed
                $deleteLog = true;
            }
        }else{
            // character deactivated location logging
            $deleteLog = true;
        }

        if($deleteLog){
            $this->deleteLog();
        }

        return $this;
    }

    /**
     * get 'character log' history data. Filter all data that does not represent a 'jump' (systemId change)
     * -> e.g. If just 'shipTypeId' has changed, this entry is filtered
     * @param int $systemIdPrev
     * @return array
     */
    protected function getLogHistoryJumps(int $systemIdPrev =  0) : array {
        return $this->filterLogsHistory(function(array $historyEntry) use (&$systemIdPrev) : bool {
            $addEntry = false;
            if(
                !empty($historySystemId = (int)$historyEntry['log']['system']['id']) &&
                $historySystemId !== $systemIdPrev
            ){
                $addEntry = true;
                $systemIdPrev = $historySystemId;
            }

            return $addEntry;
        });
    }

    /**
     * filter 'character log' history data by $callback
     * -> reindex array keys! Otherwise json_encode() on result would return object!
     * @param \Closure $callback
     * @return array
     */
    protected function filterLogsHistory(\Closure $callback) : array {
        return array_values(array_filter($this->getLogsHistory() , $callback));
    }

    /**
     * @return array
     */
    public function getLogsHistory() : array {
        if(!is_array($logHistoryData = $this->getCacheData(self::DATA_CACHE_KEY_LOG_HISTORY))){
            $logHistoryData = [];
        }
        return $logHistoryData;
    }

    /**
     * seconds after which a 'character log' counts as inactive
     * -> same threshold the cleanup cron (Cron\CharacterUpdate) uses for deleting logs
     * @return int
     */
    public static function getLogInactiveTime() : int {
        $inactiveTime = (int)self::getF3()->get('PATHFINDER.CACHE.CHARACTER_LOG_INACTIVE');
        return ($inactiveTime > 0) ? $inactiveTime : self::CHARACTER_LOG_INACTIVE;
    }

    /**
     * add new 'character log' history entry
     * @param CharacterLogModel $characterLog
     * @param string $action
     */
    public function updateLogsHistory(CharacterLogModel $characterLog, string $action = 'update') : void {
        if(
            $this->valid() &&
            $this->_id === $characterLog->get('characterId', true)
        ){
            $task = 'add';
            $mapIds = [];
            $gap = null;
            $historyLog = $characterLog::toArray($characterLog->getData());
            /** @var object{updated:mixed} $characterLog */
            $stamp = strtotime($characterLog->updated);

            if($logHistoryData = $this->getLogsHistory()){
                // skip logging if no relevant fields changed
                [$historyEntryPrev] = $logHistoryData;
                if($historyLogPrev = $historyEntryPrev['log']){
                    if(
                        $historyLog['system']['id']     === $historyLogPrev['system']['id'] &&
                        $historyLog['ship']['typeId']   === $historyLogPrev['ship']['typeId'] &&
                        $historyLog['station']['id']    === $historyLogPrev['station']['id'] &&
                        $historyLog['structure']['id']  === $historyLogPrev['structure']['id']
                    ){
                        // no changes in 'relevant' fields -> just update timestamp
                        $task = 'update';
                        $mapIds = (array)$historyEntryPrev['mapIds'];
                        // keep 'gap' of the original state change ('stamp' refresh must not reset it)
                        $gap = isset($historyEntryPrev['gap']) ? $historyEntryPrev['gap'] : null;
                    }else{
                        // seconds of "polling blind spot" between the previous log state and this one.
                        // a large gap means the character was NOT tracked in between (e.g. cleanup/poll
                        // cron died, ESI outage) -> a systemId change across it is untrusted as a "jump"
                        $gap = $stamp - (int)$historyEntryPrev['stamp'];
                    }
                }
            }

            $historyEntry = [
                'stamp'     => $stamp,
                'action'    => $action,
                'mapIds'    => $mapIds,
                'gap'       => $gap,
                'log'       => $historyLog
            ];

            if($task == 'update'){
                $logHistoryData[0] = $historyEntry;
            }else{
                array_unshift($logHistoryData, $historyEntry);

                // limit max history data
                array_splice($logHistoryData, self::MAX_LOG_HISTORY_DATA);
            }

            $this->updateCacheData($logHistoryData, self::DATA_CACHE_KEY_LOG_HISTORY, self::TTL_LOG_HISTORY);
        }
    }

    /**
     * try to update existing 'character log' history entry (replace data)
     * -> matched by 'stamp' timestamp
     * @param array $historyEntry
     * @return bool
     */
    protected function updateLogHistoryEntry(array $historyEntry) : bool {
        $updated = false;

        if(
            $this->valid() &&
            ($logHistoryData = $this->getLogsHistory())
        ){
            $map = function(array $entry) use ($historyEntry, &$updated) : array {
                if($entry['stamp'] === $historyEntry['stamp']){
                    $updated = true;
                    $entry = $historyEntry;
                }
                return $entry;
            };

            $logHistoryData = array_map($map, $logHistoryData);

            if($updated){
                $this->updateCacheData($logHistoryData, self::DATA_CACHE_KEY_LOG_HISTORY, self::TTL_LOG_HISTORY);
            }
        }

        return $updated;
    }

    /**
     * broadcast characterData
     */
    public function broadcastCharacterUpdate(){
        $characterData = $this->getData(true);

        self::getF3()->webSocket()->write('characterUpdate', $characterData);
    }

    /**
     * update character data from CCPs ESI API
     * @return array (some status messages)
     * @throws \Exception
     */
    public function updateFromESI() : array {
        $status = [];

        if( $accessToken = $this->getAccessToken() ){
            // et basic character data
            // -> this is required for "ownerHash" hash check (e.g. character was sold,..)
            // -> the "id" check is just for security and should NEVER fail!
            $ssoController = new Sso();
            if(
                !empty( $verificationCharacterData = $ssoController->verifyCharacterData($accessToken) ) &&
                $verificationCharacterData->characterId === $this->_id
            ){
                // get character data from API
                $characterData = $ssoController->getCharacterData($this->_id);
                if( !empty($characterData->character) ){
                    $characterData->character['ownerHash'] = $verificationCharacterData->owner;
                    $characterData->character['esiScopes'] = $verificationCharacterData->scp;

                    $this->copyfrom($characterData->character, ['ownerHash', 'esiScopes', 'securityStatus']);
                    $this->corporationId = $characterData->corporation;
                    $this->allianceId = $characterData->alliance;
                    $this->save();
                }
            }else{
                $status[] = sprintf(Sso::ERROR_VERIFY_CHARACTER, $this->name);
            }
        }else{
            $status[] = sprintf(Sso::ERROR_ACCESS_TOKEN, $this->name);
        }

        return $status;
    }

    /**
     * get a unique cookie name for this character
     * -> cookie name does not have to be "secure"
     * -> but is should be unique
     * @return string
     */
    public function getCookieName() : string {
        return md5($this->name);
    }

    /**
     * get the character log entry for this character
     * @return CharacterLogModel|null
     */
    public function getLog() : ?CharacterLogModel {
        return ($this->hasLog() && !$this->characterLog->dry()) ? $this->characterLog : null;
    }

    /**
     * get the first matched (most recent) log entry before $systemId.
     * -> The returned log entry *might* be previous system for this character
     * @param int $mapId
     * @param int $systemId
     * @return CharacterLogModel|null
     */
    public function getLogPrevSystem(int $mapId, int $systemId) : ?CharacterLogModel {
        $characterLog = null;

        if($mapId && $systemId){
            $skipRest = false;
            $logHistoryData = $this->filterLogsHistory(function(array $historyEntry) use ($mapId, $systemId, &$skipRest) : bool {
                $addEntry = false;
                //if(in_array($mapId, (array)$historyEntry['mapIds'], true)){   // $historyEntry is checked by EACH map -> would auto add system on map switch! #827
                if(!empty((array)$historyEntry['mapIds'])){                     // if $historyEntry was already checked by ANY other map -> no further checks
                    $skipRest = true;
                }

                if(
                    !$skipRest &&
                    (int)$historyEntry['log']['system']['id'] === $systemId &&
                    isset($historyEntry['gap']) &&
                    (int)$historyEntry['gap'] > self::getLogInactiveTime()
                ){
                    // the state change INTO the current system crossed a polling blind spot larger
                    // than the log cleanup threshold (e.g. cleanup cron died and a stale log survived
                    // a session break). Whatever comes before it can not be trusted as jump source
                    // -> better miss one connection than draw a wrong one
                    $skipRest = true;
                }

                if(
                    !$skipRest &&
                    !empty($historySystemId = (int)$historyEntry['log']['system']['id']) &&
                    $historySystemId !== $systemId
                ){
                    $addEntry = true;
                    $skipRest = true;
                }

                return $addEntry;
            });

            if(
                !empty($historyEntry = reset($logHistoryData)) &&
                is_array($historyEntry['mapIds'])
            ){
                /** @var object $characterLog */
                $characterLog = $this->rel('characterLog');
                $characterLog->setData($historyEntry['log']);

                // mark $historyEntry data as "checked" for $mapId
                array_push($historyEntry['mapIds'], $mapId);

                $this->updateLogHistoryEntry($historyEntry);
            }
        }

        return $characterLog;
    }

    /**
     * get mapModel by id and check if user has access
     * @param $mapId
     * @return MapModel|null
     * @throws \Exception
     */
    public function getMap(int $mapId) : ?MapModel {
        /** @var MapModel $map */
        $map = self::getNew('MapModel');
        $map->getById($mapId);

        return $map->hasAccess($this) ? $map : null;
    }

    /**
     * get all accessible map models for this character
     * @return MapModel[]
     */
    public function getMaps() : array {
        if(Config::getPathfinderData('login.session_sharing') === 1){
            $maps = $this->getSessionCharacterMaps();
        }else{
            $maps = [];

            if($alliance = $this->getAlliance()){
                $maps = array_merge($maps, $alliance->getMaps());
            }

            if($corporation = $this->getCorporation()){
                $maps = array_merge($maps,  $corporation->getMaps());
            }

            if(is_object($this->characterMaps)){
                $mapCountPrivate = 0;
                foreach($this->characterMaps as $characterMap){
                    if(
                        $mapCountPrivate < Config::getMapsDefaultConfig('private')['max_count'] &&
                        $characterMap->mapId->isActive()
                    ){
                        $maps[] = $characterMap->mapId;
                        $mapCountPrivate++;
                    }
                }
            }
        }

        // 로그인한 모든 캐릭터에게 기본 맵(ID: 3)에 자동 접근 권한 부여
        /** @var MapModel $defaultMap */
        $defaultMap = self::getNew('MapModel');
        $defaultMap->getById(3);
        if($defaultMap->valid() && $defaultMap->isActive()){
            // 중복 추가 방지
            $alreadyHasDefault = false;
            foreach($maps as $m){
                if($m->_id === $defaultMap->_id){
                    $alreadyHasDefault = true;
                    break;
                }
            }
            if(!$alreadyHasDefault){
                $maps[] = $defaultMap;
            }
        }

        return $maps;
    }

    /** 
     * get all accessible map models for all characters in session
     * using mapIds and characters index arrays to track what has already been processed
     * @return MapModel[]
     */
    public function getSessionCharacterMaps() : array {
        $maps = ["maps" => [], "mapIds" => []];
        
        // get all characters in session and iterate over them
        $sessionCharacters = (array)$this->getF3()->get(User::SESSION_KEY_CHARACTERS);
        foreach($this->getAll(array_column($sessionCharacters, 'ID')) as $character){            
            if($alliance = $character->getAlliance()){
                foreach($alliance->getMaps() as $map){
                    if(!in_array($map->_id, $maps["mapIds"])){
                        array_push($maps["maps"], $map);
                        array_push($maps["mapIds"], $map->id);
                    }
                }
            }

            if($corporation = $character->getCorporation()){
                foreach($corporation->getMaps() as $map){
                    if(!in_array($map->_id, $maps["mapIds"])){
                        array_push($maps["maps"], $map);
                        array_push($maps["mapIds"], $map->id);
                    }
                }
            }

            if(is_object($character->characterMaps)){
                $mapCountPrivate = 0;
                foreach($character->characterMaps as $characterMap){
                    if(
                        $mapCountPrivate < Config::getMapsDefaultConfig('private')['max_count'] &&
                        $characterMap->mapId->isActive()
                    ){
                        array_push($maps["maps"], $characterMap->mapId);
                        $mapCountPrivate++;
                    }
                }
            }
        }

        return $maps["maps"];
    }

    /**
     * delete current location
     */
    protected function deleteLog(){
        if($characterLog = $this->getLog()){
            $characterLog->erase();
        }
    }

    /**
     * delete authentications data
     */
    protected function deleteAuthentications(){
        if(is_object($this->characterAuthentications)){
            foreach($this->characterAuthentications as $characterAuthentication){
                /**
                 * @var $characterAuthentication CharacterAuthenticationModel
                 */
                $characterAuthentication->erase();
            }
        }
    }
    /**
     * character logout
     * @param bool $deleteLog
     * @param bool $deleteSession
     * @param bool $deleteCookie
     */
    public function logout(bool $deleteSession = true, bool $deleteLog = true, bool $deleteCookie = false){
        // delete current session data --------------------------------------------------------------------------------
        if($deleteSession){
            $sessionCharacterData = (array)$this->getF3()->get(User::SESSION_KEY_CHARACTERS);
            $sessionCharacterData = array_filter($sessionCharacterData, function($data){
                return ($data['ID'] != $this->_id);
            });

            if(empty($sessionCharacterData)){
                // no active characters logged in -> log user out
                $this->getF3()->clear(User::SESSION_KEY_USER);
                $this->getF3()->clear(User::SESSION_KEY_CHARACTERS);
            }else{
                // update remaining active characters
                $this->getF3()->set(User::SESSION_KEY_CHARACTERS, $sessionCharacterData);
            }
        }

        // delete current location data -------------------------------------------------------------------------------
        if($deleteLog){
            $this->deleteLog();
        }

        // delete auth cookie data ------------------------------------------------------------------------------------
        if($deleteCookie){
            $this->deleteAuthentications();
        }
    }

    /**
     * @see parent
     */
    public function filterRel() : void {
        $this->filter('userCharacter', self::getFilter('active', true));
        $this->filter('corporationId', self::getFilter('active', true));
        $this->filter('allianceId', self::getFilter('active', true));
        $this->filter('characterMaps', self::getFilter('active', true), ['order' => 'created']);
    }

    /**
     * merges two multidimensional characterSession arrays by checking characterID
     * @param array $characterDataBase
     * @return array
     */
    public static function mergeSessionCharacterData(array $characterDataBase = []) : array {
        $addData = [];
        // get current session characters to be merged with
        $characterData = (array)self::getF3()->get(User::SESSION_KEY_CHARACTERS);

        foreach($characterDataBase as $i => $baseData){
            foreach($characterData as $data){
                if((int)$baseData['ID'] === (int)$data['ID']){
                    // overwrite static data -> should NEVER change on merge!
                    $characterDataBase[$i]['NAME'] = $data['NAME'];
                    $characterDataBase[$i]['TIME'] = $data['TIME'];
                }else{
                    $addData[] = $data;
                }
            }
        }

        return array_merge($characterDataBase, $addData);
    }

    /**
     * get all characters
     * @param array $characterIds
     * @return \DB\CortexCollection
     */
    public static function getAll($characterIds = []){
        $query = [
            'active = :active AND id IN :characterIds',
            ':active' => 1,
            ':characterIds' => $characterIds
        ];

        return (new self())->find($query);
    }

    /**
     * get individual character map rights
     * mirrors CorporationModel::getRights() pattern
     * @param array|null $names   right names filter (e.g. ['map_delete']). null = all RIGHTS
     * @param array      $options ['addInactive' => bool]
     * @return CharacterRightModel[]
     * @throws \Exception
     */
    public function getRights($names = null, $options = []) : array {
        $characterRights = [];

        if(is_null($names)){
            $names = CorporationModel::RIGHTS;
        }

        /** @var RightModel $right */
        $right = self::getNew('RightModel');
        if($rights = $right->find(['active = ? AND name IN (?)', 1, $names])){
            // filter stored rights by active status unless addInactive requested
            if(empty($options['addInactive'])){
                $this->filter('characterRights', ['active = ?', 1]);
            }

            foreach($rights as $tempRight){
                $characterRight = false;
                if($this->characterRights){
                    foreach($this->characterRights as $tempCharacterRight){
                        if((int)$tempCharacterRight->get('rightId', true) === (int)$tempRight->_id){
                            $characterRight = $tempCharacterRight;
                            break;
                        }
                    }
                }

                if(!$characterRight){
                    /** @var CharacterRightModel $characterRight */
                    $characterRight = self::getNew('CharacterRightModel');
                    // Cortex ORM이 INSERT 시 FK를 올바르게 직렬화하도록 객체 대신 raw int 할당
                    $characterRight->characterId = (int)$this->_id;
                    $characterRight->rightId     = (int)$tempRight->_id;
                    $characterRight->active       = 0;
                }

                $characterRights[] = $characterRight;
            }
        }

        return $characterRights;
    }
}