<?php


namespace Exodus4D\Pathfinder\Lib\Db;


use DB\SQL\Schema;
use Exodus4D\Pathfinder\Lib\Metrics;

class Sql extends \DB\SQL {

    /**
     * SQL constructor.
     * @param $dsn
     * @param null $user
     * @param null $pw
     * @param array|null $options
     */
    public function __construct($dsn, $user = null, $pw = null, array $options = null){
        parent::__construct($dsn, $user, $pw, $options);
    }

    /**
     * get DB DSN config string
     * @return string
     */
    public function getDSN() : string {
        return $this->dsn;
    }

    /**
     * get all table names
     * @return array|bool
     */
    public function getTables(){
        $schema = new Schema($this);
        return $schema->getTables();
    }

    /**
     * checks whether a table exists or not
     * @param string $table
     * @return bool
     */
    public function tableExists(string $table) : bool {
        return in_array($table, $this->getTables());
    }

    /**
     * get current row (data) count for an existing table
     * -> returns 0 if table not exists or empty
     * @param string $table
     * @return int
     */
    public function getRowCount(string $table) : int {
        $count = 0;
        if($this->tableExists($table)){
            $countRes = $this->exec("SELECT COUNT(*) `num` FROM " . $this->quotekey($table));
            if(isset($countRes[0]['num'])){
                $count = (int)$countRes[0]['num'];
            }
        }
        return $count;
    }

    /**
     * @param string|null $table
     * @return array|null
     */
    public function getTableStatus(?string $table) : ?array {
        $status = null;
        $sql = "SHOW TABLE STATUS";
        $args = null;
        if(!empty($table)){
            $sql .= " LIKE :table";
            $args = [
                ':table' => $table
            ];
        }

        if(!empty($statusRes = $this->exec($sql, $args))){
            if(!empty($table)){
                $status = reset($statusRes);
            }else{
                $status = $statusRes;
            }
        }

        return $status;
    }

    /**
     * set some default config for this DB
     * @param string $characterSetDatabase
     * @param string $collationDatabase
     */
    public function prepareDatabase(string $characterSetDatabase, string $collationDatabase){
        if($this->name() && $characterSetDatabase && $collationDatabase){
            // set/change default "character set" and "collation"
            $this->exec('ALTER DATABASE ' . $this->quotekey($this->name())
                . ' CHARACTER SET ' . $characterSetDatabase
                . ' COLLATE ' . $collationDatabase
            );
        }
    }

    /**
     * @see https://fatfreeframework.com/3.6/sql#exec
     * @param array|string $cmds
     * @param null $args
     * @param int $ttl
     * @param bool $log (we use false as default parameter)
     * @param bool $stamp
     * @return array|FALSE|int
     */
    function exec($cmds, $args = null, $ttl = 0, $log = false, $stamp = false) {
        $start = microtime(true);
        try {
            return parent::exec($cmds, $args, $ttl, $log, $stamp);
        } finally {
            $duration = microtime(true) - $start;
            $cmd = is_array($cmds) ? (string)reset($cmds) : (string)$cmds;
            $op = strtolower(strtok(ltrim($cmd), " \t\n("));
            if(!in_array($op, ['select', 'insert', 'update', 'delete', 'replace', 'show', 'set', 'alter', 'create'])){
                $op = 'other';
            }
            Metrics::histogram('pf_db_query_duration_seconds', [
                'db' => (string)$this->name(),
                'op' => $op,
            ], $duration, Metrics::BUCKETS_DB);

            // 1초 이상 걸린 쿼리는 stderr(→ docker logs → Loki)로 원문 일부를 남긴다
            if($duration > 1.0){
                error_log(sprintf(
                    '[SLOW_SQL] db=%s op=%s duration=%.3fs query=%s',
                    $this->name(), $op, $duration, substr(preg_replace('/\s+/', ' ', $cmd), 0, 300)
                ));
            }
        }
    }
}