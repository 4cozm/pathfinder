<?php
/**
 * 같은 컨테이너의 내부 상태 페이지(:8081 vhost)를 읽는다.
 *
 * php-fpm status 와 nginx stub_status 는 원래 MetricsController 안에만 있었는데,
 * 서버 상태 API(Api\Rest\ServerStatus)도 같은 값을 필요로 한다. 취득부를 여기로
 * 모아 두 곳이 같은 출처를 쓰게 한다 — 복붙해두면 타임아웃이나 경로가 갈라지고,
 * 그때부터 "메트릭과 상태 화면의 숫자가 다르다"는 추적 불가능한 버그가 생긴다.
 *
 * 렌더링은 공유하지 않는다. MetricsController 는 Prometheus 텍스트를,
 * ServerStatus 는 JSON 을 만들며 그 둘은 서로 다른 이유로 바뀐다.
 */

namespace Exodus4D\Pathfinder\Lib;

class LocalStatus {

    /**
     * 내부 상태 페이지 서브요청 타임아웃(초).
     * 이 값을 넘기면 값을 포기한다 — 상태 조회가 요청을 붙들고 있는 것이
     * 상태를 못 보는 것보다 나쁘다.
     */
    const FETCH_TIMEOUT = 1.0;

    const BASE_URL = 'http://127.0.0.1:8081';

    /**
     * 내부 상태 페이지 본문을 가져온다.
     * @param string $path
     * @return string|null 실패 시 null (호출부가 '측정 불가'로 처리해야 한다)
     */
    public static function fetch(string $path) : ?string {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::FETCH_TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents(self::BASE_URL . $path, false, $context);

            return ($body === false) ? null : $body;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * php-fpm pool status (JSON)
     * @return array|null
     */
    public static function fpmStatus() : ?array {
        if(is_null($json = self::fetch('/fpm-status?json'))){
            return null;
        }
        $status = json_decode($json, true);

        return is_array($status) ? $status : null;
    }
}
