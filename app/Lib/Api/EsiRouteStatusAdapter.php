<?php

namespace Exodus4D\Pathfinder\Lib\Api;

use Exodus4D\Pathfinder\Lib\Config;

/**
 * Minimal adapter for ESI Route and Status APIs to avoid global changes.
 */
class EsiRouteStatusAdapter {

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
     * get route from ESI (POST)
     * @param int $originId
     * @param int $destinationId
     * @param array $options
     * @return array
     */
    public function getRoute(int $originId, int $destinationId, array $options = []) : array {
        $url = rtrim($this->esiUrl, '/') . '/latest/route/' . $originId . '/' . $destinationId . '/';
        $url .= '?datasource=' . $this->datasource;

        // ESI POST /route/ Body
        $body = [
            'connections' => $options['connections'] ?? [],
            'flag' => $options['flag'] ?? 'shortest'
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'X-Compatibility-Date' => '2018-04-03',
            'User-Agent' => $this->userAgent
        ];

        $requestOptions = [
            'method' => 'POST',
            'header' => $this->formatHeaders($headers),
            'content' => json_encode($body),
            'timeout' => 10
        ];

        $response = \Web::instance()->request($url, $requestOptions);
        $data = $this->parseResponse($response);

        // Normalize response to match existing application expectations
        // Existing app (Route.php) expects: ['route' => [ [...], [...] ]]
        if(!isset($data['error']) && is_array($data)){
            return ['route' => $data];
        }

        return $data;
    }

    /**
     * get ESI meta status
     * @return array
     */
    public function getStatus() : array {
        $url = rtrim($this->esiUrl, '/') . '/meta/status?datasource=' . $this->datasource;

        $headers = [
            'User-Agent' => $this->userAgent
        ];

        $requestOptions = [
            'method' => 'GET',
            'header' => $this->formatHeaders($headers),
            'timeout' => 5
        ];

        $response = \Web::instance()->request($url, $requestOptions);
        $data = $this->parseResponse($response);

        // Normalize response to match existing application expectations
        // Existing app expects: ['status' => [ [...], [...] ]]
        if(!isset($data['error']) && is_array($data)){
             return ['status' => $data];
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
     * @param array $response
     * @return array
     */
    private function parseResponse(array $response) : array {
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
