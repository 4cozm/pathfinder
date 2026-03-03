<?php

namespace Exodus4D\Pathfinder\Controller\Api;

use Base;
use Exodus4D\Pathfinder\Controller\Controller;
use Exodus4D\Pathfinder\Lib\Config;

/**
 * Discord(또는 봇)가 호출하는 전투 로그 집계 요청 API.
 * POST /api/CombatAggregation/request
 * Body: { "startTime": "...", "endTime": "..." } (한국시간 KST 기준, Unix timestamp 또는 ISO8601)
 *
 * 보안 로직(키교환, HMAC)이 완성되면 통과 검사 후 진행; 미완성 시 아래에서 드랍.
 */
class CombatAggregation extends Controller
{
    /**
     * 집계 요청 수신. 보안 통과 시 requestId 생성, WS 브로드캐스트 트리거, 3분 후 /combat/end 예약.
     *
     * TODO: 검증 통과 시 아래 주석 해제 및 로직 실행.
     */
    public function request(Base $f3)
    {
        // 보안 로직(키교환, HMAC)이 완성되면 통과. 미완성 시 아래 return으로 요청 드랍.
        $f3->status(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'security not configured']);
        return;

        // ----- 보안 통과 시에만 도달 (현재 미구현으로 위에서 return) -----
        // TODO: HMAC(body, shared_secret) vs Header 값 비교

        $body = $this->readJsonBody();
        if (!is_array($body)) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Bad body']);
            return;
        }

        $startTime = $body['startTime'] ?? null;
        $endTime = $body['endTime'] ?? null;
        if ($startTime === null || $endTime === null) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Missing startTime or endTime']);
            return;
        }

        $requestId = $this->generateRequestId();

        // WS 서버에 브로드캐스트 트리거: TCP 소켓으로 combatAggregationStart 전송 (MapUpdate::receiveData에서 standalone 연결 전체에 전달)
        $this->triggerCombatAggregationStartViaSocket($f3, $requestId, $startTime, $endTime);

        // 3분 후 Worker /combat/end 호출 예약 (cron 또는 지연 큐에서 처리할 수 있도록 requestId 저장)
        $this->scheduleCombatEnd($requestId);

        $this->jsonOut($f3, 200, [
            'ok'        => true,
            'requestId' => $requestId,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ]);
    }

    private function readJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function jsonOut(Base $f3, int $status, array $data): void
    {
        $f3->status($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * TCP 소켓으로 WS 프로세스에 combatAggregationStart 전송.
     * MapUpdate::receiveData에서 standalone 연결 전체에 combatAggregation.start 브로드캐스트.
     * 페이로드: requestId, startTime, endTime (한국시간 KST 기준).
     */
    private function triggerCombatAggregationStartViaSocket(Base $f3, string $requestId, $startTime, $endTime): void
    {
        if (!$f3->exists(\Exodus4D\Pathfinder\Lib\Socket\TcpSocket::SOCKET_NAME)) {
            return;
        }
        try {
            $socket = $f3->get(\Exodus4D\Pathfinder\Lib\Socket\TcpSocket::SOCKET_NAME);
            if (!$socket instanceof \Exodus4D\Pathfinder\Lib\Socket\SocketInterface) {
                return;
            }
            $load = [
                'requestId' => $requestId,
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ];
            $socket->write('combatAggregationStart', $load);
        } catch (\Throwable $e) {
            // 로그만 (실패 시 클라이언트는 집계 요청을 받지 못함)
        }
    }

    /**
     * 3분 후 Worker /combat/end 호출 예약.
     * Redis에 requestId + 만료 시간 저장해 두면, cron 또는 별도 워커가 주기적으로 확인 후
     * 만료된 requestId에 대해 Worker POST /combat/end 호출.
     * 구현 방식은 운영 정책에 따라 선택 (주석으로 의도 유지).
     */
    private function scheduleCombatEnd(string $requestId): void
    {
        $dsn = getenv('REDIS_DSN');
        if (!is_string($dsn) || $dsn === '') {
            return;
        }
        try {
            $client = new \Predis\Client($dsn);
            $key = 'combat_aggregation:end:' . $requestId;
            $client->setex($key, 180 + 60, (string)time()); // 3분(180초) + 여유 60초 TTL
        } catch (\Throwable $e) {
            // 로그만
        }
    }
}
