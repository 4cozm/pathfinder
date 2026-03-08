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
            $this->dispatch($f3, $params, $character);
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
                    // current character is admin
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
        if($character instanceof CharacterModel){
            // user logged in
            $parts = array_values(array_filter(array_map('strtolower', explode('/', $params['*']))));
            $f3->set('tplPage', $parts[0]);

            switch($parts[0]){
                case 'settings':
                    switch($parts[1]){
                        case 'save':
                            $objectId = (int)$parts[2];
                            $values  = (array)$f3->get('GET');
                            $this->saveSettings($character, $objectId, $values);

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
     * kick or revoke a character
     * @param CharacterModel $character
     * @param int $kickCharacterId
     * @param int $minutes
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

        $f3->set('tplSettings', $data);
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