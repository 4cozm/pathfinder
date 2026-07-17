<?php

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Lib\Metrics;

/**
 * Minimal adapter for ESI Route and Status APIs to avoid global changes.
 *
 * 2025-09 ESI 개편(compatibility date 체계) 대응:
 *  - 구 GET /latest/route/ 는 제거 예고, 구 compat date(2018-04-03)는 400 거부됨
 *  - 신 POST /route/{o}/{d} 는 body 스키마 변경: flag → preference(대소문자 enum),
 *    connections 가 [[from,to],...] 쌍 배열 → [{"from":id,"to":id},...] 객체 배열
 *  - 신 GET /meta/status 는 X-Compatibility-Date 헤더 필수(없으면 404),
 *    상태값이 green/yellow/red → OK/Degraded/Down/Recovering 으로 변경
 *
 * 신 상태값은 이 어댑터 경계에서 구 색상(green/yellow/red)으로 번역한다
 * → Controller/프론트엔드의 기존 판정 로직을 수정하지 않기 위함 (anticorruption layer).
 */
class EsiRouteStatusAdapter {

    /**
     * ESI compatibility date (검증된 날짜만 사용할 것)
     * 유효 목록: GET https://esi.evetech.net/meta/compatibility-dates
     * 올릴 때는 route/status 응답 스키마 변화를 확인하고 올린다.
     */
    const COMPATIBILITY_DATE = '2026-06-09';

    /**
     * 신규 ESI preference enum (대소문자 정확히 — "shorter"는 422 거부됨)
     * 구 flag 값 → 신 preference 매핑
     */
    const ROUTE_PREFERENCE_MAP = [
        'shortest'  => 'Shorter',
        'secure'    => 'Safer',
        'insecure'  => 'LessSecure',
    ];

    /**
     * 신규 ESI status → 구 색상 코드 번역 (기존 UI/판정 로직 호환)
     */
    const STATUS_COLOR_MAP = [
        'OK'         => 'green',
        'Degraded'   => 'yellow',
        'Recovering' => 'yellow',
        'Down'       => 'red',
    ];

    /**
     * @var string
     */
    private $esiUrl;

    /**
     * @var string
     */
    private $datasource;

    /**
     * @var string
     */
    private $userAgent;

    /**
     * EsiRouteStatusAdapter constructor.
     * @param string $userAgent
     */
    public function __construct(string $userAgent = ''){
        $this->esiUrl = Config::getEnvironmentData('CCP_ESI_URL');
        $this->datasource = Config::getEnvironmentData('CCP_ESI_DATASOURCE');
        $this->userAgent = $userAgent;
    }

    /**
     * get route from ESI (POST /route/{origin}/{destination}, unversioned + compat date)
     * @param int $originId
     * @param int $destinationId
     * @param array $options ['flag' => shortest|secure|insecure, 'connections' => [[from,to],...]]
     * @return array ['route' => [systemId,...]] | ['error' => string]
     */
    public function getRoute(int $originId, int $destinationId, array $options = []) : array {
        $url = rtrim($this->esiUrl, '/') . '/route/' . $originId . '/' . $destinationId;
        $url .= '?datasource=' . $this->datasource;

        $body = [
            'preference'  => self::ROUTE_PREFERENCE_MAP[$options['flag'] ?? 'shortest'] ?? 'Shorter',
            'connections' => $this->formatConnections((array)($options['connections'] ?? [])),
        ];

        $data = $this->request('POST', $url, $body, 10, 'adapterGetRoute');

        // 신규 응답은 이미 {"route": [...]} 형태 — 그대로 반환.
        // (방어: 혹시 bare array 로 오면 기존 기대 형태로 래핑)
        if(!isset($data['error']) && is_array($data) && !isset($data['route'])){
            return ['route' => $data];
        }

        return $data;
    }

    /**
     * get ESI meta status (X-Compatibility-Date 필수 — 없으면 404)
     * @return array ['status' => [['route' =>, 'method' =>, 'status' => green|yellow|red],...]] | ['error' => string]
     */
    public function getStatus() : array {
        $url = rtrim($this->esiUrl, '/') . '/meta/status?datasource=' . $this->datasource;

        $data = $this->request('GET', $url, null, 5, 'adapterGetStatus');

        if(isset($data['error'])){
            return $data;
        }

        // 신규 형식 {"routes":[{"method","path","status"}]} → 구 형식(route/method/색상)으로 번역
        $rows = [];
        foreach((array)($data['routes'] ?? []) as $routeData){
            $rows[] = [
                'route'  => (string)($routeData['path'] ?? ''),
                'method' => strtolower((string)($routeData['method'] ?? '')),
                'status' => self::STATUS_COLOR_MAP[$routeData['status'] ?? ''] ?? 'yellow',
            ];
        }

        return ['status' => $rows];
    }

    /**
     * [[from,to],...] 쌍 배열 → 신규 스키마 [{"from":id,"to":id},...] 변환
     * (이미 객체 형태로 들어오면 그대로 통과)
     * @param array $connections
     * @return array
     */
    private function formatConnections(array $connections) : array {
        $formatted = [];
        foreach($connections as $pair){
            if(is_array($pair) && isset($pair['from'], $pair['to'])){
                $formatted[] = ['from' => (int)$pair['from'], 'to' => (int)$pair['to']];
            }elseif(is_array($pair) && count($pair) >= 2){
                $pair = array_values($pair);
                $formatted[] = ['from' => (int)$pair[0], 'to' => (int)$pair[1]];
            }
        }
        return $formatted;
    }

    /**
     * shared HTTP request + metrics
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @param int $timeout
     * @param string $metricEndpoint
     * @return array
     */
    private function request(string $method, string $url, ?array $body, int $timeout, string $metricEndpoint) : array {
        $headers = [
            'Accept'                => 'application/json',
            'X-Compatibility-Date'  => self::COMPATIBILITY_DATE,
            'User-Agent'            => $this->userAgent,
        ];
        if(!is_null($body)){
            $headers['Content-Type'] = 'application/json';
        }

        $requestOptions = [
            'method'  => $method,
            'header'  => $this->formatHeaders($headers),
            'timeout' => $timeout,
        ];
        if(!is_null($body)){
            $requestOptions['content'] = json_encode($body);
        }

        $start = microtime(true);
        $response = \Web::instance()->request($url, $requestOptions);
        $data = $this->parseResponse($response);

        // 이 어댑터는 CcpClient(공용 계측 지점)를 우회하므로 여기서 직접 계측
        Metrics::histogram('pf_esi_request_duration_seconds', ['endpoint' => $metricEndpoint],
            microtime(true) - $start, Metrics::BUCKETS_OUTBOUND);
        if(isset($data['error'])){
            Metrics::counter('pf_esi_errors_total', ['endpoint' => $metricEndpoint]);
            error_log(sprintf('[ESI_ADAPTER_FAIL] endpoint=%s error=%s', $metricEndpoint,
                substr((string)$data['error'], 0, 200)));
        }

        return $data;
    }

    /**
     * @param array $headers
     * @return array
     */
    private function formatHeaders(array $headers) : array {
        $formatted = [];
        foreach($headers as $key => $value){
            $formatted[] = $key . ': ' . $value;
        }
        return $formatted;
    }

    /**
     * @param array|null $response
     * @return array
     */
    private function parseResponse(?array $response) : array {
        if (empty($response['body'])) {
            return ['error' => 'Empty response from ESI'];
        }

        $data = json_decode($response['body'], true);
        if(json_last_error() !== JSON_ERROR_NONE){
            return ['error' => 'Invalid JSON response from ESI'];
        }

        // Check for HTTP error status in the status line
        if (isset($response['headers'][0]) && preg_match('/HTTP\/[0-9\.]+\s+([0-9]+)/', $response['headers'][0], $matches)) {
            $status = (int)$matches[1];
            if ($status >= 400) {
                return ['error' => $data['error'] ?? 'ESI API Error (' . $status . ')'];
            }
        }

        return $data;
    }
}
