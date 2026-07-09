<?php
/**
 * CorpAclModel.php
 * 코퍼레이션 단위 ACL — 로그인 허용(canLogin) / 맵 편집 허용(canEdit) / 만료(expires)를 한 행으로 관리.
 * 기존에 흩어져 있던 로그인 화이트리스트(pathfinder.ini [PATHFINDER.LOGIN])와
 * corporation_right 매트릭스를 대체한다.
 *
 * 권한 우선순위: character_acl(개인) → corp_acl(코퍼) → 기본 차단. SUPER(ini)는 항상 최우선.
 * 설계 문서: docs/CORP_ACL_DESIGN.md
 */

namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;
use Exodus4D\Pathfinder\Lib\Config;

class CorpAclModel extends AbstractPathfinderModel {

    /**
     * @var string
     */
    protected $table = 'corp_acl';

    /**
     * @var array
     */
    protected $fieldConf = [
        'corporationId' => [
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
            // 절대 만료 시각. NULL = 무제한. (기간 저장 금지 — 설계 문서 3장)
            // DATETIME 사용: TIMESTAMP 의 암묵적 DEFAULT/ON UPDATE CURRENT_TIMESTAMP 와 NULL 저장 불가 문제 회피.
            'type'     => Schema::DT_DATETIME,
            'nullable' => true,
            'default'  => null,
            'index'    => true
        ],
        'updatedBy' => [
            // 마지막으로 설정한 SUPER 캐릭터 id (감사용)
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
        $data->corporationId = (int)$this->corporationId;
        $data->canLogin      = (bool)$this->canLogin;
        $data->canEdit       = (bool)$this->canEdit;
        $data->expires       = $this->expires ? : null;
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
     * corporationId로 단일 행 로드
     * @param int $corporationId
     * @return self|null
     */
    public static function getByCorporationId(int $corporationId) : ?self {
        if($corporationId <= 0){
            return null;
        }
        $model = new self();
        $model->load(['corporationId = ?', $corporationId]);
        return $model->dry() ? null : $model;
    }

    /**
     * 일회성 시드 (A안): corp_acl이 비어있을 때만 ini LOGIN.CORPORATION → corp_acl 이행.
     * 이미 행이 있으면 아무것도 하지 않는다 → 이후 ini 변경은 무시된다.
     * @throws \Exception
     */
    public static function seedFromConfig() : void {
        if((new self())->count() > 0){
            return; // 이미 시드됨
        }

        $whitelist = array_filter(array_map('trim', (array)Config::getPathfinderData('login.corporation')));
        foreach($whitelist as $corporationId){
            $corporationId = (int)$corporationId;
            if($corporationId <= 0){
                continue;
            }
            $row = new self();
            $row->corporationId = $corporationId;
            $row->canLogin      = 1;
            $row->canEdit       = 0;
            $row->expires       = null;
            $row->save();
            $row->reset();
        }
    }

    /**
     * setup table + unique index(corporationId) + 일회성 시드
     * @param null $db
     * @param null $table
     * @param null $fields
     * @return bool
     * @throws \Exception
     */
    public static function setup($db = null, $table = null, $fields = null){
        if($status = parent::setup($db, $table, $fields)){
            $status = parent::setMultiColumnIndex(['corporationId'], true);
            self::seedFromConfig();
        }
        return $status;
    }
}
