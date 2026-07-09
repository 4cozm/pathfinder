<?php
/**
 * CharacterRightModel.php
 * 개별 캐릭터에 대한 맵 접근 권한(map_create, map_delete 등)을 관리하는 모델.
 * CorporationRightModel과 동일한 구조: character ↔ right 다대다 관계.
 */

namespace Exodus4D\Pathfinder\Model\Pathfinder;

use DB\SQL\Schema;

class CharacterRightModel extends AbstractPathfinderModel {

    /**
     * @var string
     */
    protected $table = 'character_right';

    /**
     * @var array
     */
    protected $fieldConf = [
        'active' => [
            'type'      => Schema::DT_BOOL,
            'nullable'  => false,
            'default'   => 1,
            'index'     => true
        ],
        'characterId' => [
            'type'          => Schema::DT_INT,
            'index'         => true,
            'belongs-to-one'=> 'Exodus4D\Pathfinder\Model\Pathfinder\CharacterModel',
            'constraint'    => [
                [
                    'table'     => 'character',
                    'on-delete' => 'CASCADE'
                ]
            ]
        ],
        'rightId' => [
            'type'          => Schema::DT_INT,
            'index'         => true,
            'belongs-to-one'=> 'Exodus4D\Pathfinder\Model\Pathfinder\RightModel',
            'constraint'    => [
                [
                    'table'     => 'right',
                    'on-delete' => 'CASCADE'
                ]
            ]
        ]
    ];

    /**
     * set data from associative array
     * @param array $data
     */
    public function setData($data){
        unset($data['id'], $data['created'], $data['updated']);

        foreach((array)$data as $key => $value){
            if(!is_array($value)){
                if(array_key_exists($key, $this->fieldConf)){
                    $this->$key = $value;
                }
            }
        }
    }

    /**
     * get character right data
     * @return \stdClass
     */
    public function getData(){
        $data = (object) [];
        $data->right = $this->rightId->getData();
        return $data;
    }

    /**
     * setup table and indexes
     * @param null $db
     * @param null $table
     * @param null $fields
     * @return bool
     * @throws \Exception
     */
    public static function setup($db = null, $table = null, $fields = null){
        if($status = parent::setup($db, $table, $fields)){
            $status = parent::setMultiColumnIndex(['characterId', 'rightId'], true);
        }
        return $status;
    }
}
