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
use Exodus4D\Pathfinder\Model\Pathfinder\CorpAclModel;
use Exodus4D\Pathfinder\Model\Pathfinder\CharacterAclModel;

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
     * [SECURITY][CSRF] 상태 변경(mutating) 관리자 액션에 대한 same-origin 검증.
     * 세션 쿠키가 SameSite=Lax 라서 cross-site "최상위 GET 네비게이션"은 쿠키를 그대로 실어 보낼 수 있다.
     * kick/ban/map delete/ACL remove/spydetect delete 등은 UI에서 GET <a> 링크(또는 GET XHR)로 트리거되므로
     * POST-only 로 강제하면 정상 흐름이 깨진다. 대신 요청의 Origin/Referer host 가 앱 host 와 일치하는지
     * 확인하여 cross-site 로 유발된 변경을 차단한다(정상 관리 UI 는 항상 same-origin 이므로 통과한다).
     * 불일치하거나 둘 다 없으면 403 후 즉시 종료한다.
     * @param \Base $f3
     */
    protected function assertSameOrigin(\Base $f3): void {
        $appHost = preg_replace('/:\d+$/', '', (string)$f3->get('HEADERS.Host'));
        $source  = (string)$f3->get('HEADERS.Origin');
        if($source === ''){
            $source = (string)$f3->get('HEADERS.Referer');
        }
        $sourceHost = $source !== '' ? parse_url($source, PHP_URL_HOST) : null;

        if($appHost === '' || !is_string($sourceHost) || strcasecmp($sourceHost, $appHost) !== 0){
            $f3->status(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Forbidden: cross-origin request rejected'], JSON_UNESCAPED_UNICODE);
            exit;
        }
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
                    // settings(ACL) 페이지: SUPER는 전체 수정, CORPORATION(매니저)은 읽기 전용
                    if(!in_array($character->roleId->name, ['SUPER', 'CORPORATION'], true)){
                        $f3->reroute('@admin(@*=/)');
                        return;
                    }
                    $isSuper = ($character->roleId->name === 'SUPER');

                    // 변경 동작(corp/character)은 SUPER만 허용
                    if(isset($parts[1]) && in_array($parts[1], ['corp', 'character'], true)){
                        if(!$isSuper){
                            $f3->reroute('@admin(@*=/settings)');
                            return;
                        }

                        $action   = $parts[2] ?? '';
                        $objectId = (int)($parts[3] ?? 0);

                        // corp 검색(info)은 JSON 반환 후 즉시 종료
                        if($parts[1] === 'corp' && $action === 'info'){
                            $this->getCorporationInfo($f3, $objectId);
                            return;
                        }

                        // [AI NOTE] HTML form 은 항상 POST + array_merge. GET 은 프록시(Traefik)에서 유실될 수 있음.
                        $values = array_merge((array)$f3->get('GET'), (array)$f3->get('POST'));

                        // [SECURITY][CSRF] save/remove(상태 변경) 실행 전 same-origin 검증
                        $this->assertSameOrigin($f3);

                        if($parts[1] === 'corp'){
                            switch($action){
                                case 'save':   $this->saveCorpAcl($character, $objectId, $values); break;
                                case 'remove': $this->removeCorpAcl($character, $objectId); break;
                            }
                        }else{ // character
                            switch($action){
                                case 'save':   $this->saveCharacterAcl($character, $objectId, $values); break;
                                case 'remove': $this->removeCharacterAcl($character, $objectId); break;
                            }
                        }

                        $f3->reroute('@admin(@*=/settings)');
                        break;
                    }

                    $f3->set('tplIsSuper', $isSuper);
                    $this->initSettings($f3, $character);
                    break;
                case 'members':
                    switch($parts[1]){
                        case 'kick':
                            // [SECURITY][CSRF] 상태 변경 전 same-origin 검증
                            $this->assertSameOrigin($f3);
                            $objectId = (int)$parts[2];
                            $value  = (int)$parts[3];
                            $this->kickCharacter($character, $objectId, $value);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                        case 'ban':
                            // [SECURITY][CSRF] 상태 변경 전 same-origin 검증
                            $this->assertSameOrigin($f3);
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
                            // [SECURITY][CSRF] 상태 변경 전 same-origin 검증
                            $this->assertSameOrigin($f3);
                            $objectId = (int)$parts[2];
                            $value  = (int)$parts[3];
                            $this->activateMap($character, $objectId, $value);

                            $f3->reroute('@admin(@*=/' . $parts[0] . ')');
                            break;
                        case 'delete':
                            // [SECURITY][CSRF] 상태 변경 전 same-origin 검증
                            $this->assertSameOrigin($f3);
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
                        // [SECURITY][CSRF] 상태 변경(관계 삭제) 전 same-origin 검증
                        $this->assertSameOrigin($f3);
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
     * 만료 입력값(절대 시각 문자열)을 검증·정규화한다.
     * - 빈 값 → null (무제한)
     * - 과거 시각 → now 로 클램프(= 즉시 만료). 시계 오차로 살짝 과거인 경우도 동일.
     * - 파싱 실패 → null (안전하게 무제한 취급)
     * @param mixed $value
     * @return string|null  'Y-m-d H:i:s' 또는 null
     */
    protected function parseExpires($value) : ?string {
        $value = trim((string)$value);
        if($value === ''){
            return null; // 무제한
        }
        try{
            $timezone = self::getF3()->get('getTimeZone')();
            $dt  = new \DateTime($value, $timezone);
            $now = new \DateTime('now', $timezone);
            if($dt->getTimestamp() < $now->getTimestamp()){
                $dt = $now; // 과거 → 즉시 만료
            }
            return $dt->format('Y-m-d H:i:s');
        }catch(\Exception $e){
            return null;
        }
    }

    /**
     * corp_acl upsert (로그인/편집/만료). 행 없으면 생성.
     * @param CharacterModel $adminCharacter
     * @param int $corporationId
     * @param array $settings  canLogin, canEdit, expires(절대 시각)
     * @throws \Exception
     */
    protected function saveCorpAcl(CharacterModel $adminCharacter, int $corporationId, array $settings){
        if($corporationId <= 0){
            return;
        }
        $acl = CorpAclModel::getByCorporationId($corporationId);
        if(!$acl){
            $acl = CorpAclModel::getNew('CorpAclModel');
            $acl->corporationId = $corporationId;
        }
        $acl->canLogin  = !empty($settings['canLogin']) ? 1 : 0;
        $acl->canEdit   = !empty($settings['canEdit']) ? 1 : 0;
        $acl->expires   = $this->parseExpires($settings['expires'] ?? '');
        $acl->updatedBy = (int)$adminCharacter->_id;
        $acl->save();
    }

    /**
     * corp_acl 하드 제거 (목록에서 완전히 삭제). A안에서는 ini 재시드가 없으므로 부활하지 않는다.
     * @param CharacterModel $adminCharacter
     * @param int $corporationId
     * @throws \Exception
     */
    protected function removeCorpAcl(CharacterModel $adminCharacter, int $corporationId){
        if($acl = CorpAclModel::getByCorporationId($corporationId)){
            $acl->erase();
        }
    }

    /**
     * character_acl upsert (로그인/편집/만료/메모). 행 없으면 생성.
     * init 모드(검색으로 추가)면 기본값(canLogin=1, canEdit=0)으로 1회 생성만 한다.
     * @param CharacterModel $adminCharacter
     * @param int $targetCharacterId
     * @param array $settings  canLogin, canEdit, expires(절대 시각), memo, init
     * @throws \Exception
     */
    protected function saveCharacterAcl(CharacterModel $adminCharacter, int $targetCharacterId, array $settings){
        if($targetCharacterId <= 0){
            return;
        }

        $acl = CharacterAclModel::getByCharacterId($targetCharacterId);

        if(!empty($settings['init'])){
            // 검색으로 신규 추가: 이미 있으면 그대로 두고, 없으면 기본값으로 생성 (보기만)
            if(!$acl){
                $acl = CharacterAclModel::getNew('CharacterAclModel');
                $acl->characterId = $targetCharacterId;
                $acl->canLogin    = 1;
                $acl->canEdit     = 0;
                $acl->expires     = null;
                $acl->memo        = trim((string)($settings['memo'] ?? ''));
                $acl->updatedBy   = (int)$adminCharacter->_id;
                $acl->save();
            }
            return;
        }

        if(!$acl){
            $acl = CharacterAclModel::getNew('CharacterAclModel');
            $acl->characterId = $targetCharacterId;
        }
        $acl->canLogin  = !empty($settings['canLogin']) ? 1 : 0;
        $acl->canEdit   = !empty($settings['canEdit']) ? 1 : 0;
        $acl->expires   = $this->parseExpires($settings['expires'] ?? '');
        if(isset($settings['memo'])){
            $acl->memo = trim((string)$settings['memo']);
        }
        $acl->updatedBy = (int)$adminCharacter->_id;
        $acl->save();
    }

    /**
     * character_acl 하드 제거 (개인 예외 완전 삭제 → 해당 캐릭터는 corp 정책으로 복귀).
     * @param CharacterModel $adminCharacter
     * @param int $targetCharacterId
     * @throws \Exception
     */
    protected function removeCharacterAcl(CharacterModel $adminCharacter, int $targetCharacterId){
        if($acl = CharacterAclModel::getByCharacterId($targetCharacterId)){
            $acl->erase();
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
     * init /settings (ACL) page data
     * -> corp_acl / character_acl 행을 화면용 데이터로 변환한다.
     * @param \Base $f3
     * @param CharacterModel $character
     * @throws \Exception
     */
    protected function initSettings(\Base $f3, CharacterModel $character){
        $data = (object) [];
        $timezone = $f3->get('getTimeZone')();

        // corp ACL 목록 (로그인 허용 우선, 그다음 만료 임박 순)
        $data->corpAcls = [];
        $corpAclModel = CorpAclModel::getNew('CorpAclModel');
        if($corpRows = $corpAclModel->find(null, ['order' => 'canLogin DESC, expires'])){
            foreach($corpRows as $row){
                $corpId = (int)$row->corporationId;
                $corp = CorporationModel::getNew('CorporationModel');
                $corp->getById($corpId);
                $data->corpAcls[] = (object)[
                    'id'        => $corpId,
                    'name'      => $corp->valid() ? $corp->name : ('ID: ' . $corpId),
                    'canLogin'  => (bool)$row->canLogin,
                    'canEdit'   => (bool)$row->canEdit,
                    'expires'   => $row->expires ? : '',
                    'updatedBy' => (int)$row->updatedBy
                ];
                $corp->reset();
            }
        }

        // character ACL 목록
        $data->characterAcls = [];
        $charAclModel = CharacterAclModel::getNew('CharacterAclModel');
        if($charRows = $charAclModel->find(null, ['order' => 'canLogin DESC, expires'])){
            foreach($charRows as $row){
                $charId = (int)$row->characterId;
                $char = CharacterModel::getNew('CharacterModel');
                $char->load(['id = ?', $charId]);
                // 로컬 character 테이블에 행이 없으면(=이 패파에 아직 로그인 이력 없음) 이름을 모른다.
                // 이름은 매 렌더마다 즉석 조회하므로, 해당 캐릭터가 1회 로그인하면 자동으로 닉네임으로 바뀐다.
                $nameResolved = !$char->dry();
                $data->characterAcls[] = (object)[
                    'id'           => $charId,
                    'name'         => $nameResolved ? $char->name : ('ID: ' . $charId),
                    'nameResolved' => $nameResolved,
                    'canLogin'  => (bool)$row->canLogin,
                    'canEdit'   => (bool)$row->canEdit,
                    'expires'   => $row->expires ? : '',
                    'memo'      => (string)$row->memo,
                    'updatedBy' => (int)$row->updatedBy
                ];
                $char->reset();
            }
        }

        // JS 만료 계산 기준이 되는 서버 현재 시각
        $data->serverNow = (new \DateTime('now', $timezone))->format('Y-m-d H:i:s');

        $f3->set('tplSettings', $data);
    }

    /**
     * GET /admin/settings/corp/info/{corporationId}
     * ESI에서 코퍼레이션 기본 정보를 가져와 JSON 반환 (코퍼 추가 검색용)
     * @param \Base $f3
     * @param int $corporationId
     * @throws \Exception
     */
    protected function getCorporationInfo(\Base $f3, int $corporationId){
        $return = (object) ['ok' => false];

        if($corporationId > 0){
            $corp = CorporationModel::getNew('CorporationModel');
            $corp->getById($corporationId);
            if($corp->valid()){
                $return->ok       = true;
                $return->id       = $corporationId;
                $return->name     = $corp->name;
                $return->logo_url = 'https://images.evetech.net/corporations/' . $corporationId . '/logo?size=64';
            }
        }

        header('Content-Type: application/json');
        echo json_encode($return);
        exit;
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