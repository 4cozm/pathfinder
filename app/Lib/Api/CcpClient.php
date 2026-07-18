<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 26.12.2018
 * Time: 17:43
 */

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Lib\Metrics;
use Exodus4D\ESI\Client\ApiInterface;
use Exodus4D\ESI\Client\Ccp\Esi\Esi as Client;

/**
 * Class CcpClient
 * @package lib\api
 *
 * @method ApiInterface send(string $requestHandler, ...$handlerParams)
 * @method ApiInterface sendBatch(array $configs)
 */
class CcpClient extends AbstractClient {

    /**
     * @var string
     */
    const CLIENT_NAME = 'ccpClient';

    /**
     * @param \Base $f3
     * @return ApiInterface|null
     */
    protected function getClient(\Base $f3) : ?ApiInterface {
        $client = null;
        if(class_exists(Client::class)){
            $client = new Client(Config::getEnvironmentData('CCP_ESI_URL'));
            $client->setDataSource(Config::getEnvironmentData('CCP_ESI_DATASOURCE'));
        }else{
            $this->getLogger()->write($this->getMissingClassError(Client::class));
        }

        return $client;
    }

    /**
     * override __call to measure execution time and aggregate ESI metrics for backpressure
     * @param string $name
     * @param array $arguments
     * @return array|mixed
     */
    public function __call(string $name, array $arguments = []){
        $startTime = microtime(true);
        $return = parent::__call($name, $arguments);
        $duration = microtime(true) - $startTime;

        // 호출은 대부분 send('getXxx',...)/sendBatch() 래퍼를 경유하므로 $name만 쓰면
        // 전부 endpoint="send"로 뭉개진다 → 실제 핸들러명을 라벨로 사용
        if($name === 'send' && isset($arguments[0]) && is_string($arguments[0])){
            $endpoint = $arguments[0];
        }elseif($name === 'sendBatch' && isset($arguments[0]) && is_array($arguments[0])){
            // batch 는 포함된 핸들러명 목록으로 라벨링 (병렬이라 duration = max(개별))
            $handlers = array_map(function($config){
                return (is_array($config) && is_string($config[0] ?? null)) ? $config[0] : '?';
            }, $arguments[0]);
            $endpoint = 'batch(' . implode('+', $handlers) . ')';
        }else{
            $endpoint = $name;
        }

        if($redis = $this->getRedis()){
            $bucketId = floor($startTime / 5);
            $key = 'PF_ESI_METRICS_' . $bucketId;
            
            $isError = isset($return['error']) ? 1 : 0;
            $isSlow = ($duration > 1.5) ? 1 : 0; // Use a more aggressive 'slow' threshold for pressure signal

            $redis->hIncrBy($key, 'total', 1);
            if($isError) $redis->hIncrBy($key, 'fail', 1);
            if($isSlow) $redis->hIncrBy($key, 'slow', 1);
            $redis->hIncrBy($key, 'latency_sum', (int)($duration * 1000));
            $redis->expire($key, 300); // Keep for 5 minutes
        }

        if($duration > 2.0){
            $this->getLogger('SLOW_ESI')->write(sprintf('Critically slow ESI call: %s (%.2fs)', $endpoint, $duration));
        }

        // Prometheus 노출용 (기존 5초 버킷은 backpressure 전용이라 유지)
        // $endpoint = ESI 핸들러명 (getCharacterLocation 등) → 유한 카디널리티
        Metrics::histogram('pf_esi_request_duration_seconds', ['endpoint' => $endpoint], $duration, Metrics::BUCKETS_OUTBOUND);
        if(isset($return['error'])){
            Metrics::counter('pf_esi_errors_total', ['endpoint' => $endpoint]);
        }

        return $return;
    }

    /**
     * get Redis instance
     * @return \Redis|null
     */
    protected function getRedis() : ?\Redis {
        static $redis = null;
        if(is_null($redis) && extension_loaded('redis')){
            $redis = new \Redis();
            try {
                if(!$redis->pconnect(
                    Config::getEnvironmentData('REDIS_HOST'),
                    Config::getEnvironmentData('REDIS_PORT') ? : 6379,
                    Config::REDIS_OPT_TIMEOUT
                )){
                    $redis = null;
                } else if($auth = Config::getEnvironmentData('REDIS_AUTH')){
                    $redis->auth($auth);
                }
            } catch (\Exception $e) {
                $redis = null;
            }
        }
        return $redis;
    }
}