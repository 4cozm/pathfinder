<?php
/**
 * CharacterAclModel.php
 * 개별 캐릭터 ACL — corp_acl과 동일 형태(canLogin / canEdit / expires) + 메모.
 * 기존 character_right(액션별 허용/차단)를 대체하며, corp_acl을 "완전 오버라이드"한다(허용·차단 둘 다).
 *
 * 권한 우선순위: character_acl(개인) → corp_acl(코퍼) → 기본 차단. SUPER(ini)는 항상 최우선.
 * 개인 entry가 만료되면 그 줄을 무시하고 corp_acl 정책으로 fallback.
 * 설계 문서: docs/CORP_ACL_DESIGN.md
 */

namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;
use Exodus4D\Pathfinder\Lib\Config;

class CharacterAclModel extends AbstractPathfinderModel {

    /**
     * @var string
     */
    protected $table = 'character_acl';

    /**
     * @var array
     */
    protected $fieldConf = [
        'characterId' => [
            'type'     => Schema::DT_BIGINT,
            'nullable' => false,
            'index'    => true
        ],
        'canLogin' => [
            'type'     => Schema::DT_BOOL,
            'nullable' => false,
            'default'  => 1,
            'index'    => true
        ],
        'canEdit' => [
            'type'     => Schema::DT_BOOL,
            'nullable' => false,
            'default'  => 0
        ],
        'expires' => [
            // 절대 만료 시각. NULL = 무제한.
            // DATETIME 사용: TIMESTAMP 의 암묵적 DEFAULT/ON UPDATE CURRENT_TIMESTAMP 와 NULL 저장 불가 문제 회피.
            'type'     => Schema::DT_DATETIME,
            'nullable' => true,
            'default'  => null,
            'index'    => true
        ],
        'memo' => [
            'type'     => Schema::DT_VARCHAR512,
            'nullable' => false,
            'default'  => ''
        ],
        'updatedBy' => [
            'type' => Schema::DT_BIGINT
        ]
    ];

    /**
     * set data from associative array
     * @param array $data
     */
    public function setData($data){
        unset($data['id'], $data['created'], $data['updated']);

        foreach((array)$data as $key => $value){
            if(!is_array($value) && array_key_exists($key, $this->fieldConf)){
                $this->$key = $value;
            }
        }
    }

    /**
     * @return \stdClass
     */
    public function getData(){
        $data = (object) [];
        $data->characterId = (int)$this->characterId;
        $data->canLogin    = (bool)$this->canLogin;
        $data->canEdit     = (bool)$this->canEdit;
        $data->expires     = $this->expires ? : null;
        $data->memo        = (string)$this->memo;
        return $data;
    }

    /**
     * 만료 여부. NULL(무제한)이면 만료 아님.
     * @return bool
     */
    public function isExpired() : bool {
        if(!$this->expires){
            return false;
        }
        try{
            $timezone = self::getF3()->get('getTimeZone')();
            $now      = new \DateTime('now', $timezone);
            $expires  = new \DateTime($this->expires, $timezone);
            return $expires->getTimestamp() <= $now->getTimestamp();
        }catch(\Exception $e){
            // 파싱 실패 → 만료로 간주(deny 방향). default-deny 기조와 일치.
            return true;
        }
    }

    /**
     * 로그인 허용 && 미만료
     * @return bool
     */
    public function allowsLogin() : bool {
        return (bool)$this->canLogin && !$this->isExpired();
    }

    /**
     * 편집 허용 && 미만료
     * @return bool
     */
    public function allowsEdit() : bool {
        return (bool)$this->canEdit && !$this->isExpired();
    }

    /**
     * characterId로 단일 행 로드 (미만료 여부 무관 — 호출부에서 isExpired로 판단)
     * @param int $characterId
     * @return self|null
     */
    public static function getByCharacterId(int $characterId) : ?self {
        if($characterId <= 0){
            return null;
        }
        $model = new self();
        $model->load(['characterId = ?', $characterId]);
        return $model->dry() ? null : $model;
    }

    /**
     * 일회성 마이그레이션 (A안): character_acl이 비어있을 때만
     *   (1) 기존 character_right 의 캐릭터들
     *   (2) ini LOGIN.CHARACTER 화이트리스트
     * 를 character_acl 로 이행한다. 모두 canLogin=1, canEdit=0 (안전 기본). 메모(adminMemo) 보존.
     * @throws \Exception
     */
    public static function migrateFromLegacy() : void {
        if((new self())->count() > 0){
            return; // 이미 이행됨
        }

        $characterIds = [];

        // (1) 기존 character_right 의 distinct characterId
        $crm = CharacterRightModel::getNew('CharacterRightModel');
        if($rows = $crm->find()){
            foreach($rows as $row){
                $cId = (int)$row->get('characterId', true);
                if($cId > 0){
                    $characterIds[$cId] = true;
                }
            }
        }

        // (2) ini LOGIN.CHARACTER 개인 화이트리스트
        $whitelist = array_filter(array_map('trim', (array)Config::getPathfinderData('login.character')));
        foreach($whitelist as $cId){
            $cId = (int)$cId;
            if($cId > 0){
                $characterIds[$cId] = true;
            }
        }

        foreach(array_keys($characterIds) as $characterId){
            // 메모 보존 (기존 CharacterModel.adminMemo)
            $memo = '';
            $character = CharacterModel::getNew('CharacterModel');
            $character->load(['id = ?', $characterId]);
            if(!$character->dry()){
                $memo = (string)$character->adminMemo;
            }
            $character->reset();

            $aclRow = new self();
            $aclRow->characterId = $characterId;
            $aclRow->canLogin    = 1;
            $aclRow->canEdit     = 0;
            $aclRow->expires     = null;
            $aclRow->memo        = $memo;
            $aclRow->save();
            $aclRow->reset();
        }
    }

    /**
     * setup table + unique index(characterId) + 일회성 마이그레이션
     * @param null $db
     * @param null $table
     * @param null $fields
     * @return bool
     * @throws \Exception
     */
    public static function setup($db = null, $table = null, $fields = null){
        if($status = parent::setup($db, $table, $fields)){
            $status = parent::setMultiColumnIndex(['characterId'], true);
            self::migrateFromLegacy();
        }
        return $status;
    }
}
