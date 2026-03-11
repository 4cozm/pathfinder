<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 12.05.2017
 * Time: 20:30
 *
 * Note: Uses Fat-Free Framework (F3) and Cortex ORM. Many intelephense "Undefined property/type" warnings
 * are false positives: \Template and \Log are F3 globals; model instances get dynamic properties (id, _id,
 * name, roleId, rightId, etc.) from the ORM. Runtime behavior is correct.
 */

namespace Exodus4D\Pathfinder\Controller;


use Exodus4D\Pathfinder\Controller\Ccp\Sso;
use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel;
use Exodus4D\Pathfinder\Model\Pathfinder\CorporationModel;
use Exodus4D\Pathfinder\Model\Pathfinder\MapModel;
use Exodus4D\Pathfinder\Model\Pathfinder\RoleModel;
use Exodus4D\Pathfinder\Model\Pathfinder\CharacterRightModel;

class Admin extends Controller{

    const ERROR_SSO_CHARACTER_EXISTS                = 'No character found. Please login first.';
    const ERROR_SSO_CHARACTER_SCOPES                = 'Additional ESI scopes are required for "%s". Use the SSO button below.';
    const ERROR_SSO_CHARACTER_ROLES                 = 'Insufficient in-game roles. "%s" requires at least one of these corp roles: %s.';

    const LOG_TEXT_KICK_BAN                         = '%s "%s" from corporation "%s", by "%s"';

    const KICK_OPTIONS = [
        5 => '5m',
        60 => '1h',
        1440 => '24h'
    ];

    /**
     * event handler for all "views"
     * some global template variables are set in here
     * @param \Base $f3
     * @param $params
     * @return bool
     * @throws \Exception
     */
    function beforeroute(\Base $f3, $params): bool {
        $return = parent::beforeroute($f3, $params);

        $f3->set('tplPage', 'login');

        if($character = $this->getAdminCharacter($f3)){
            $f3->set('tplLogged', true);
            $f3->set('character', $character);
            // dispatch() 호출 제거: F3의 라우팅 시스템이 자동으로 dispatch()를 호출하므로 여기서 호출하면 두 번 실행됨
        }

        $f3->set('tplAuthType', $f3->get('BASE') . $f3->alias( 'sso', ['action' => 'requestAdminAuthorization']));

        // page title
        $f3->set('tplPageTitle', 'Admin | ' . Config::getPathfinderData('name'));

        // main page content
        $f3->set('tplPageContent', Config::getPathfinderData('view.admin'));

        // body element class
        $f3->set('tplBodyClass', 'pf-landing');

        return $return;
    }

    /**
     * event handler after routing
     * @param \Base $f3
     */
    public function afterroute(\Base $f3) {
        // js view (file)
        $f3->set('tplJsView', 'admin');

        // render view
        echo \Template::instance()->render( Config::getPathfinderData('view.index') );

        // clear all SSO related temp data
        if( $f3->exists(Sso::SESSION_KEY_SSO) ){
            $f3->clear('SESSION.SSO.ERROR');
        }
    }

    /**
     * returns valid admin $characterModel for current user
     * @param \Base $f3
     * @return CharacterModel|null
     * @throws \Exception
     */
    protected function getAdminCharacter(\Base $f3){
        $adminCharacter = null;
        if( !$f3->exists(Sso::SESSION_KEY_SSO_ERROR) ){
            if( $character = $this->getCharacter(0) ){
                if(in_array($character->roleId->name, ['SUPER', 'CORPORATION'], true)){
                    // [SECURITY] 현재 캐릭터가 DB상에서 SUPER 또는 CORPORATION 권한을 가지고 있음
                    // CharacterModel->getRole()에서 EVE 인게임 직책에 의한 자동 승격 로직을 제거했으므로,
                    // 여기에 도달하는 캐릭터는 오직 pathfinder.ini에 명시되었거나 DB에서 수동으로 격승된 인원뿐임
                    $adminCharacter = $character;
                }elseif( !$character->hasAdminScopes() ){
                    $f3->set(Sso::SESSION_KEY_SSO_ERROR,
                        sprintf(
                            self::ERROR_SSO_CHARACTER_SCOPES,
                            $character->name
                        ));
                }else{
                    $f3->set(Sso::SESSION_KEY_SSO_ERROR,
                        sprintf(
                            self::ERROR_SSO_CHARACTER_ROLES,
                            $character->name,
                            implode(', ', CorporationModel::ADMIN_ROLES)
                        ));
                }
            }else{
                $f3->set(Sso::SESSION_KEY_SSO_ERROR, self::ERROR_SSO_CHARACTER_EXISTS);
            }
        }

        return $adminCharacter;
    }

    /**
     * dispatch page events by URL $params
     * @param \Base $f3
     * @param $params
     * @param null $character
     * @throws \Exception
     */
    public function dispatch(\Base $f3, $params, $character = null){
        // beforeroute에서 설정한 character 객체를 가져옴
        $character = $f3->get('character');
        if($character instanceof CharacterModel){
            // user logged in
            $parts = array_values(array_filter(array_map('strtolower', explode('/', $params['*']))));
            $f3->set('tplPage', $parts[0]);

            switch($parts[0]){
                case 'settings':
                    // settings 페이지 및 API는 SUPER(admin)만 접근 가능
                    if ($character->roleId->name !== 'SUPER') {
                        $f3->reroute('@admin(@*=/)');
                        return;
                    }
                    switch($parts[1]){
                        case 'save':
                            $objectId = (int)$parts[2];
                            $values  = array_merge((array)$f3->get('GET'), (array)$f3->get('POST'));
                            $this->saveSettings($character, $objectId, $values);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                        case 'savepersonal':
                            $objectId = (int)$parts[2];
                            // [AI NOTE] IMPORTANT: Always use POST + array_merge for settings forms.
                            // GET queries from HTML forms might be stripped by the server/proxy (e.g. Traefik).
                            $values  = array_merge((array)$f3->get('GET'), (array)$f3->get('POST'));

                            // [DEBUG LOG]
                            error_log(sprintf('--- Admin::savePersonal --- Character: %s, ObjectId: %d, Method: %s', $character->name, $objectId, $f3->get('VERB')));
                            error_log('Values: ' . print_r($values, true));

                            $this->savePersonalSettings($character, $objectId, $values);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;

                        case 'removepersonal':
                            $objectId = (int)$parts[2];
                            $this->removePersonalSettings($character, $objectId);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                    }
                    $f3->set('tplDefaultRole', RoleModel::getDefaultRole());
                    $f3->set('tplRoles', RoleModel::getAll());
                    $this->initSettings($f3, $character);
                    break;
                case 'members':
                    switch($parts[1]){
                        case 'kick':
                            $objectId = (int)$parts[2];
                            $value  = (int)$parts[3];
                            $this->kickCharacter($character, $objectId, $value);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                        case 'ban':
                            $objectId = (int)$parts[2];
                            $value  = (int)$parts[3];
                            $this->banCharacter($character, $objectId, $value);
                            break;
                        case 'info':
                            // GET /admin/members/info/{uid} — ESI 캐릭터 정보 JSON 반환
                            $this->getMemberInfo($f3, (int)$parts[2]);
                            return;
                    }
                    $f3->set('tplKickOptions', self::KICK_OPTIONS);
                    $this->initMembers($f3, $character);
                    break;
                case 'maps':
                    switch($parts[1]){
                        case 'active':
                            $objectId = (int)$parts[2];
                            $value  = (int)$parts[3];
                            $this->activateMap($character, $objectId, $value);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                        case 'delete':
                            $objectId = (int)$parts[2];
                            $this->deleteMap($character, $objectId);
                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                    }
                    $this->initMaps($f3, $character);
                    break;
                case 'spydetect':
                    // spydetect 페이지 및 API는 SUPER(admin)만 접근 가능
                    if ($character->roleId->name !== 'SUPER') {
                        $f3->reroute('@admin(@*=/)');
                        return;
                    }
                    if (isset($parts[1]) && $parts[1] === 'data') {
                        $this->spydetectData($f3);
                        return;
                    }
                    if (isset($parts[1]) && $parts[1] === 'issuer' && isset($parts[2]) && ctype_digit($parts[2]) && isset($parts[3]) && $parts[3] === 'characters') {
                        $this->spydetectIssuerCharacters($f3, (int)$parts[2]);
                        return;
                    }
                    if (isset($parts[1]) && $parts[1] === 'enrich' && isset($parts[2]) && ctype_digit($parts[2])) {
                        $this->spydetectEnrich($f3, (int)$parts[2]);
                        return;
                    }
                    if (isset($parts[1]) && $parts[1] === 'issuer' && isset($parts[2]) && ctype_digit($parts[2])
                        && isset($parts[3]) && $parts[3] === 'character' && isset($parts[4]) && ctype_digit($parts[4])
                        && isset($parts[5]) && $parts[5] === 'delete') {
                        $this->spydetectDeleteCharacter($f3, (int)$parts[2], (int)$parts[4]);
                        return;
                    }
                    $this->initSpydetect($f3);
                    break;
                case 'login':
                default:
                    $f3->set('tplPage', 'login');
                    break;
            }
        }
    }

    /**
     * save or delete settings (e.g. corporation rights)
     * @param CharacterModel $character
     * @param int $corporationId
     * @param array $settings
     * @throws \Exception
     */
    protected function saveSettings(CharacterModel $character, int $corporationId, array $settings){
        $defaultRole = RoleModel::getDefaultRole();

        if($corporationId && $defaultRole){
            $corporations = $this->getAccessibleCorporations($character);
            foreach($corporations as $corporation){
                if((int)$corporation->id === $corporationId){
                    // character has access to that corporation -> create/update/delete rights...
                    if($corporationRightsData = (array)$settings['rights']){
                        // get existing corp rights
                        foreach($corporation->getRights($corporation::RIGHTS, ['addInactive' => true]) as $corporationRight){
                            $corporationRightData = $corporationRightsData[$corporationRight->rightId->_id];
                            if(
                                $corporationRightData &&
                                $corporationRightData['roleId'] != $defaultRole->_id // default roles should not be saved
                            ){
                                $corporationRight->setData($corporationRightData);
                                $corporationRight->setActive(true);
                                $corporationRight->save();
                            }else{
                                // right not send by user -> delete existing right
                                $corporationRight->erase();
                            }
                        }
                    }
                    break;
                }
            }
        }
    }

    /**
     * save personal character map rights
     * @param CharacterModel $adminCharacter  currently logged-in admin
     * @param int            $targetCharacterId  character whose rights are being saved
     * @param array          $settings  GET parameters (rights[rightId][active] = 1|0, init = 1)
     * @throws \Exception
     */
    protected function savePersonalSettings(CharacterModel $adminCharacter, int $targetCharacterId, array $settings){
        if(!$targetCharacterId) return;

        /** @var CharacterModel $targetCharacter */
        $targetCharacter = CharacterModel::getNew('CharacterModel');
        // id 컬럼으로 캐릭터 로드
        $targetCharacter->load(['id = ?', $targetCharacterId]);
        
        if(!$targetCharacter->valid()){
            // DB에 없는 캐릭터인 경우 (한 번도 접속 안 함) -> ESI에서 정보 가져와 강제 생성
            $sso = new Sso();
            try {
                $charData = $sso->getCharacterData($targetCharacterId);
                if(!empty($charData->character)){
                    $targetCharacter->id = (int)$charData->character['id'];
                    $targetCharacter->name = $charData->character['name'];
                    // 기본 역할 설정 (MEMBER)
                    $targetCharacter->roleId = RoleModel::getDefaultRole();
                    $targetCharacter->active = 1;
                    
                    // 군단/연맹 정보 업데이트 (있을 경우)
                    if($charData->corporation){
                        $targetCharacter->corporationId = (int)$charData->corporation->id;
                    }
                    if($charData->alliance){
                        $targetCharacter->allianceId = (int)$charData->alliance->id;
                    }

                    $targetCharacter->save();
                }
            } catch (\Throwable $e) {
                // ignore — character could not be created from ESI
            }
        }

        if($targetCharacter->valid() && !$targetCharacter->active){
            // 기존에 active=0으로 생성된 캐릭터가 있다면 이를 활성화 (SSO 로그인 충돌 방지)
            $targetCharacter->setActive(true);
            $targetCharacter->save();
        }

        if(!$targetCharacter->valid()){
            return;
        }

        // --- NEW: Save Memo ---
        $logger = self::getLogger('ADMIN');
        $logger->write(sprintf('savePersonalSettings called for char=%s, memo_isset=%d, memo_val=%s', $targetCharacterId, isset($settings['memo']), $settings['memo'] ?? 'null'));

        if(isset($settings['memo'])){
            $targetCharacter->adminMemo = trim($settings['memo']);
            $targetCharacter->save();
            $logger->write(sprintf('saved memo: %s', $targetCharacter->adminMemo));
        }
        // -----------------------

        $isInit = !empty($settings['init']);
        $personalRightsData = (array)($settings['rights'] ?? []);

        // 모든 사용 가능한 Right 목록을 직접 조회
        /** @var \Exodus4D\Pathfinder\Model\Pathfinder\RightModel $rightModel */
        $rightModel = CharacterModel::getNew('RightModel');
        $allRights = $rightModel->find(['active = ? AND name IN (?)', 1, CorporationModel::RIGHTS]);
        if(!$allRights){
            return;
        }

        foreach($allRights as $tempRight){
            $rightId = (int)$tempRight->_id;

            // 해당 캐릭터+권한 조합의 기존 레코드를 직접 load (getRights() 우회)
            /** @var \Exodus4D\Pathfinder\Model\Pathfinder\CharacterRightModel $cr */
            $cr = CharacterModel::getNew('CharacterRightModel');
            // characterId 필드는 CharacterModel의 _id와 매핑됨
            $cr->load(['characterId = ? AND rightId = ?', $targetCharacter->id, $rightId]);

            if($isInit){
                // 최초 추가: DB에 없으면 active=0으로 신규 생성
                if($cr->dry()){
                    $cr->characterId = $targetCharacter->id;
                    $cr->rightId     = $rightId;
                    $cr->setActive(false);
                    $cr->save();
                }
            }else{
                // 체크박스 저장: 체크된 것은 active=1 upsert, 그 외는 erase
                $isActive = isset($personalRightsData[$rightId])
                    && (int)($personalRightsData[$rightId]['active'] ?? 0) === 1;

                if($isActive){
                    // 기존 레코드 없으면 새로 생성, 있으면 update
                    if($cr->dry()){
                         $cr->characterId = $targetCharacter->id;
                         $cr->rightId     = $rightId;
                    }
                    $cr->setActive(true);
                    $cr->save();
                }else{
                    // 체크 해제 -> 삭제하지 않고 비활성화 유지 (리스트 등록 상태 유지 목적)
                    if(!$cr->dry()){
                        $cr->setActive(false);
                        $cr->save();
                    }
                }
            }
        }
    }

    /**
     * 캐릭터의 모든 개인 권한을 완전히 삭제
     * @param CharacterModel $adminCharacter
     * @param int $targetCharacterId
     */
    protected function removePersonalSettings(CharacterModel $adminCharacter, int $targetCharacterId){
        $logger = self::getLogger('ADMIN');
        $logger->write(sprintf('removePersonalSettings: targetCharacterId=%s', $targetCharacterId));

        /** @var CharacterRightModel $crm */
        $crm = CharacterModel::getNew('CharacterRightModel');
        $rights = $crm->find(['characterId = ?', $targetCharacterId]);

        if($rights){
            $toErase = is_array($rights) ? $rights : iterator_to_array($rights);
            foreach($toErase as $right){
                $right->erase();
            }
            $logger->write(sprintf('removePersonalSettings: Erased all rights for charId=%s', $targetCharacterId));
        }
    }

    /**
     * kick or revoke a character
     */
    protected function kickCharacter(CharacterModel $character, $kickCharacterId, $minutes){
        $kickOptions = self::KICK_OPTIONS;
        $minKickTime = key($kickOptions) ;
        end($kickOptions);
        $maxKickTime = key($kickOptions);
        $minutes = in_array($minutes, range($minKickTime, $maxKickTime)) ? $minutes : 0;

        $kickCharacters = $this->filterValidCharacters($character, $kickCharacterId);
        foreach($kickCharacters as $kickCharacter){
            $kickCharacter->kick($minutes);
            $kickCharacter->save();

            self::getLogger()->write(
                sprintf(
                    self::LOG_TEXT_KICK_BAN,
                    $minutes ? 'KICK' : 'KICK REVOKE',
                    $kickCharacter->name,
                    $kickCharacter->getCorporation()->name,
                    $character->name
                )
            );
        }
    }

    /**
     * @param CharacterModel $character
     * @param int $banCharacterId
     * @param int $value
     */
    protected function banCharacter(CharacterModel $character, $banCharacterId, $value){
        $banCharacters = $this->filterValidCharacters($character, $banCharacterId);
        foreach($banCharacters as $banCharacter){
            $banCharacter->ban($value);
            $banCharacter->save();

            self::getLogger()->write(
                sprintf(
                    self::LOG_TEXT_KICK_BAN,
                    $value ? 'BAN' : 'BAN REVOKE',
                    $banCharacter->name,
                    $banCharacter->getCorporation()->name,
                    $character->name
                )
            );
        }
    }

    /**
     * checks whether a $character has admin access rights for $characterId
     * -> must be in same corporation
     * @param CharacterModel $character
     * @param int $characterId
     * @return array|\DB\CortexCollection
     */
    protected function filterValidCharacters(CharacterModel $character, $characterId){
        $characters = [];
        // check if kickCharacters belong to same Corp as admin character
        // -> remove admin char from valid characters...
        if( !empty($characterIds = array_diff([$characterId], [$character->_id])) ){
            if($character->roleId->name === 'SUPER'){
                if($filterCharacters = CharacterModel::getAll($characterIds)){
                    $characters = $filterCharacters;
                }
            }else{
                $characters = $character->getCorporation()->getCharacters($characterIds);
            }
        }
        return $characters;
    }

    /**
     * @param CharacterModel $character
     * @param int $mapId
     * @param int $value
     */
    protected function activateMap(CharacterModel $character, int $mapId, int $value){
        $maps = $this->filterValidMaps($character, $mapId);
        foreach($maps as $map){
            $map->setActive((bool)$value);
            $map->save($character);
        }
    }

    /**
     * @param CharacterModel $character
     * @param int $mapId
     */
    protected function deleteMap(CharacterModel $character, int $mapId){
        $maps = $this->filterValidMaps($character, $mapId);
        foreach($maps as $map){
            $map->erase();
        }
    }

    /**
     * checks whether a $character has admin access rights for $mapId
     * @param CharacterModel $character
     * @param int $mapId
     * @return \DB\CortexCollection[]|MapModel[]
     */
    protected function filterValidMaps(CharacterModel $character, int $mapId) {
        $maps = [];
        if($character->roleId->name === 'SUPER'){
            if($filterMaps = MapModel::getAll([$mapId], ['addInactive' => true])){
                $maps = $filterMaps;
            }
        }else{
            $maps = $character->getCorporation()->getMaps($mapId, ['addInactive' => true, 'ignoreMapCount' => true]);
        }

        return $maps;
    }

    /**
     * get log file for "admin" logs
     * @param string $type
     * @return \Log
     */
    static function getLogger($type = 'ADMIN') : \Log {
        return parent::getLogger('ADMIN');
    }

    /**
     * init /settings page data
     * @param \Base $f3
     * @param CharacterModel $character
     */
    protected function initSettings(\Base $f3, CharacterModel $character){
        $data = (object) [];
        $corporations = $this->getAccessibleCorporations($character);

        foreach($corporations as $corporation){
            $data->corporations[$corporation->name] = $corporation;
        }

        // Fetch characters that have at least one personal right stored
        /** @var CharacterRightModel $crm */
        $crm = CharacterRightModel::getNew('CharacterRightModel');
        $allRights = $crm->find();

        $charIds = [];
        if($allRights){
            foreach($allRights as $r){
                $cId = (int)$r->get('characterId', true);
                $charIds[] = $cId;
            }
            $charIds = array_unique(array_filter($charIds));
        }

        $data->characters = [];
        if(!empty($charIds)){
            /** @var CharacterModel $charModel */
            $charModel = CharacterModel::getNew('CharacterModel');
            if($foundChars = $charModel->find(['id IN (?)', $charIds])){
                $data->characters = $foundChars;
            }
        }

        $f3->set('tplSettings', $data);
    }

    /**
     * GET /admin/members/info/{uid}
     * ESI에서 캐릭터 기본 정보를 가져와 JSON 반환 (Personal Rights 탭 검색용)
     * @param \Base $f3
     * @param int   $uid
     */
    protected function getMemberInfo(\Base $f3, int $uid){
        $return = (object) ['ok' => false];

        if($uid){
            $sso = new Sso();
            try{
                $charData = $sso->getCharacterData($uid);
                if(!empty($charData->character)){
                    $return->ok              = true;
                    $return->id              = (int)$charData->character['id'];
                    $return->name            = $charData->character['name'];
                    $return->corporation_name = $charData->corporation ? $charData->corporation->name : 'Unknown Corp';
                    $return->portrait_url    = 'https://images.evetech.net/characters/' . $uid . '/portrait?size=64';
                }
            }catch(\Throwable $e){
                // ignore — ok stays false
            }
        }

        header('Content-Type: application/json');
        echo json_encode($return);
        exit;
    }

    /**
     * init /member page data
     * @param \Base $f3
     * @param CharacterModel $character
     */
    protected function initMembers(\Base $f3, CharacterModel $character){
        $data = (object) [];
        if($characterCorporation = $character->getCorporation()){
            $corporations = $this->getAccessibleCorporations($character);

            foreach($corporations as $corporation){
                if($characters = $corporation->getCharacters()){
                    $data->corpMembers[$corporation->name] = $characters;
                }
            }

            // sort corporation from current user first
            if( !empty($data->corpMembers[$characterCorporation->name]) ){
                $data->corpMembers = array($characterCorporation->name => $data->corpMembers[$characterCorporation->name]) + $data->corpMembers;
            }
        }

        $f3->set('tplMembers', $data);
    }

    /**
     * init /maps page data
     * @param \Base $f3
     * @param CharacterModel $character
     */
    protected function initMaps(\Base $f3, CharacterModel $character){
        $data = (object) [];
        if($characterCorporation = $character->getCorporation()){
            $corporations = $this->getAccessibleCorporations($character);

            foreach($corporations as $corporation){
                if($maps = $corporation->getMaps(null, ['addInactive' => true, 'ignoreMapCount' => true])){
                    $data->corpMaps[$corporation->name] = $maps;
                }
            }
        }

        $f3->set('tplMaps', $data);

        if( !isset($data->corpMaps) ){
            $f3->set('tplNotification', $this->getNotificationObject('No maps found',
                'Only corporation maps could get loaded' ,
                'info'
            ));
        }
    }

    /**
     * get all corporations a characters has admin access for
     * @param CharacterModel $character
     * @return CorporationModel[]
     */
    protected function getAccessibleCorporations(CharacterModel $character) {
        $corporations = [];
        if($characterCorporation = $character->getCorporation()){
            switch($character->roleId->name){
                case 'SUPER':
                    if($accessCorporations =  CorporationModel::getAll(['addNPC' => true])){
                        $corporations = $accessCorporations;
                    }
                    break;
                case 'CORPORATION':
                    $corporations[] = $characterCorporation;
                    break;
            }
        }

        return $corporations;
    }

    /**
     * init 유저 관계도(spydetect) page — 데이터는 JS가 /admin/spydetect/data 로 조회
     * @param \Base $f3
     */
    protected function initSpydetect(\Base $f3): void
    {
        // no server-side data; template + JS fetch /admin/spydetect/data
    }

    /**
     * GET /admin/spydetect/data — 발급 계정(issuer)별 건수. JSON { ok, issuers: [ { issuer_character_id, issuer_name?, detected_count } ] }
     * @param \Base $f3
     */
    protected function spydetectData(\Base $f3): void
    {
        $db = $this->getDB();
        if (!$db) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'issuers' => [], 'hint' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $logRows = $db->exec(
                'SELECT issuer_character_id, COUNT(*) AS detected_count FROM standalone_detect_log GROUP BY issuer_character_id ORDER BY detected_count DESC'
            );
        } catch (\Throwable $e) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'issuers' => [],
                'hint'    => 'standalone_detect_log table missing or DB error. Run pf-migrate-standalone (see scripts/migrate-standalone-detect.sh).',
                'error'   => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (empty($logRows)) {
            $tableExists = false;
            try {
                $t = $db->exec("SHOW TABLES LIKE 'standalone_detect_log'");
                $tableExists = !empty($t);
            } catch (\Throwable $e) {
                // ignore
            }
            $f3->status(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'           => true,
                'issuers'      => [],
                'table_exists' => $tableExists,
                'hint'         => $tableExists ? 'No data yet. Bind from dmc_helper with Chatlogs UIDs to populate.' : 'Table missing. Run pf-migrate-standalone.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $issuerIds = array_map(function ($r) { return (int)$r['issuer_character_id']; }, $logRows);
        $placeholders = implode(',', array_fill(0, count($issuerIds), '?'));
        try {
            $nameRows = $db->exec('SELECT id, name FROM `character` WHERE id IN (' . $placeholders . ')', $issuerIds);
        } catch (\Throwable $e) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'issuers' => [],
                'hint'    => 'DB error reading character names.',
                'error'   => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $namesById = [];
        foreach ($nameRows ?: [] as $r) {
            $namesById[(int)$r['id']] = $r['name'] ?? '';
        }
        $issuers = [];
        foreach ($logRows as $row) {
            $issuerId = (int)$row['issuer_character_id'];
            $issuers[] = [
                'issuer_character_id' => $issuerId,
                'issuer_name'         => $namesById[$issuerId] ?? '',
                'detected_count'      => (int)$row['detected_count'],
            ];
        }
        $f3->status(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'issuers' => $issuers], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET /admin/spydetect/issuer/{issuer_character_id}/characters — 해당 발급 계정에서 발견된 캐릭터 목록
     * @param \Base $f3
     * @param int $issuerId
     */
    protected function spydetectIssuerCharacters(\Base $f3, int $issuerId): void
    {
        $db = $this->getDB();
        if (!$db) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'characters' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $issuerName = '';
        try {
            $charRows = $db->exec('SELECT id, name FROM `character` WHERE id = :id', [':id' => $issuerId]);
            if (!empty($charRows)) {
                $issuerName = $charRows[0]['name'] ?? '';
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if ($issuerName === '') {
            try {
                $sso = new Sso();
                $charData = $sso->getCharacterData($issuerId);
                $issuerName = isset($charData->character['name']) ? (string)$charData->character['name'] : '';
            } catch (\Throwable $e) {
                $issuerName = 'Character ' . $issuerId;
            }
        }
        $characters = [];
        $errorMessage = null;
        try {
            $rows = $db->exec(
                'SELECT l.detected_character_id AS character_id, COALESCE(NULLIF(c.name, \'\'), pc.name) AS name, COALESCE(c.corporation_id, pc.corporationId) AS corporation_id, c.corporation_name, l.updated_at ' .
                'FROM standalone_detect_log l ' .
                'LEFT JOIN standalone_detect_characters c ON c.character_id = l.detected_character_id ' .
                'LEFT JOIN `character` pc ON pc.id = l.detected_character_id ' .
                'WHERE l.issuer_character_id = :issuer ORDER BY l.updated_at DESC',
                [':issuer' => $issuerId]
            );
            foreach ($rows ?: [] as $row) {
                $characters[] = [
                    'character_id'      => (int)$row['character_id'],
                    'name'              => $row['name'] ?? '',
                    'corporation_id'    => isset($row['corporation_id']) ? (int)$row['corporation_id'] : null,
                    'corporation_name'  => $row['corporation_name'] ?? '',
                    'updated_at'        => $row['updated_at'] ?? '',
                ];
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }
        $f3->status($errorMessage ? 500 : 200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'                   => !$errorMessage,
            'issuer_character_id'  => $issuerId,
            'issuer_name'          => $issuerName,
            'characters'           => $characters,
            'hint'                 => $errorMessage,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * GET /admin/spydetect/enrich/{character_id} — ESI로 보강 후 DB 갱신, JSON 반환
     * @param \Base $f3
     * @param int $characterId
     */
    protected function spydetectEnrich(\Base $f3, int $characterId): void
    {
        $db = $this->getDB();
        if (!$db) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $existing = $db->exec(
            'SELECT character_id, name, corporation_id, corporation_name, updated_at FROM standalone_detect_characters WHERE character_id = :cid',
            [':cid' => $characterId]
        );
        $row = isset($existing[0]) ? $existing[0] : null;
        if (!$row) {
            $f3->status(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Character not in list'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $name = $row['name'] ?? '';
        $corporationName = $row['corporation_name'] ?? '';
        if ($name !== '' && $corporationName !== '') {
            $f3->status(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'               => true,
                'character_id'     => (int)$row['character_id'],
                'name'             => $name,
                'corporation_id'   => isset($row['corporation_id']) ? (int)$row['corporation_id'] : null,
                'corporation_name' => $corporationName,
                'updated_at'       => $row['updated_at'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $sso = new Sso();
            $charData = $sso->getCharacterData($characterId);
            $name = isset($charData->character['name']) ? (string)$charData->character['name'] : '';
            $corporationId = null;
            $corporationName = '';
            if ($charData->corporation !== null) {
                $corporationId = (int)$charData->corporation->_id;
                $corporationName = (string)$charData->corporation->name;
            }
            $db->exec(
                'UPDATE standalone_detect_characters SET name = :name, corporation_id = :cid, corporation_name = :cname, updated_at = NOW() WHERE character_id = :charid',
                [
                    ':name'   => $name,
                    ':cid'    => $corporationId,
                    ':cname'  => $corporationName,
                    ':charid' => $characterId,
                ]
            );
            $updated = $db->exec(
                'SELECT character_id, name, corporation_id, corporation_name, updated_at FROM standalone_detect_characters WHERE character_id = :cid',
                [':cid' => $characterId]
            );
            $out = isset($updated[0]) ? $updated[0] : [
                'character_id' => $characterId,
                'name' => $name,
                'corporation_id' => $corporationId,
                'corporation_name' => $corporationName,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $f3->status(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'               => true,
                'character_id'     => (int)$out['character_id'],
                'name'             => $out['name'] ?? '',
                'corporation_id'   => isset($out['corporation_id']) ? (int)$out['corporation_id'] : null,
                'corporation_name' => $out['corporation_name'] ?? '',
                'updated_at'       => $out['updated_at'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * GET /admin/spydetect/issuer/{issuerId}/character/{characterId}/delete — 관계 삭제 후 고아면 standalone_detect_characters에서도 삭제
     * @param \Base $f3
     * @param int $issuerId
     * @param int $characterId
     */
    protected function spydetectDeleteCharacter(\Base $f3, int $issuerId, int $characterId): void
    {
        $db = $this->getDB();
        if (!$db) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $db->exec(
                'DELETE FROM standalone_detect_log WHERE issuer_character_id = :issuer AND detected_character_id = :char',
                [':issuer' => $issuerId, ':char' => $characterId]
            );
            $remaining = $db->exec(
                'SELECT 1 FROM standalone_detect_log WHERE detected_character_id = :char LIMIT 1',
                [':char' => $characterId]
            );
            if (empty($remaining)) {
                $db->exec('DELETE FROM standalone_detect_characters WHERE character_id = :char', [':char' => $characterId]);
            }
            $f3->status(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            $f3->status(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

}