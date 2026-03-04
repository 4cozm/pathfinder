<?php

namespace Exodus4D\Pathfinder\Controller\Api;

use Base;
use Exodus4D\Pathfinder\Controller\Controller;
use Exodus4D\Pathfinder\Lib\Config;

/**
 * Discord 백엔드가 호출하는 전투 로그 집계 요청 API.
 * POST /api/CombatAggregation/request
 * Header: X-Signature: HMAC-SHA256(raw_body, DISCORD_TO_PF_HMAC)
 * Body: { "startTime": "...", "endTime": "..." } (KST 기준, ISO8601 또는 Unix timestamp)
 */
class CombatAggregation extends Controller
{
    /**
     * 집계 만료 시간 (초) — 하드코딩 5분
     */
    const EXPIRES_IN = 300;

    /**
     * 집계 요청 수신.
     * HMAC 검증 → requestId(UUID v4) 생성 → Redis 작업 리스트 저장
     * → WS(TCP) 브로드캐스트 트리거 → 응답 반환
     */
    public function request(Base $f3)
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            $rawBody = '';
        }

        // HMAC-SHA256 검증
        $secret = (string)getenv('DISCORD_TO_PF_HMAC');
        if ($secret === '') {
            $this->jsonOut($f3, 500, ['ok' => false, 'message' => 'HMAC secret not configured']);
            return;
        }

        $provided  = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
        $expected  = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $provided)) {
            $this->jsonOut($f3, 403, ['ok' => false, 'message' => 'Invalid signature']);
            return;
        }

        // Body 파싱
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Bad body']);
            return;
        }

        $startTime = $body['startTime'] ?? null;
        $endTime   = $body['endTime']   ?? null;
        if ($startTime === null || $endTime === null) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Missing startTime or endTime']);
            return;
        }

        $requestId = $this->generateUuidV4();
        $now       = time();

        // Redis 작업 리스트 저장
        $this->storeDmcTask($requestId, $startTime, $endTime, $now);

        // WS 서버에 브로드캐스트 트리거 (TCP → MapUpdate::receiveData)
        $this->triggerCombatAggregationStartViaSocket($f3, $requestId, $startTime, $endTime);

        $this->jsonOut($f3, 200, [
            'ok'        => true,
            'requestId' => $requestId,
            'expiresIn' => self::EXPIRES_IN,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ]);
    }

    /**
     * UUID v4 생성 (RFC 4122)
     */
    private function generateUuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Redis에 dmc_helper 작업 저장.
     * dmc_tasks:{requestId} → JSON, TTL = EXPIRES_IN + 60(여유)
     * dmc_tasks:active      → SET, 활성 requestId 인덱스
     */
    private function storeDmcTask(string $requestId, $startTime, $endTime, int $createdAt): void
    {
        $dsn = (string)getenv('REDIS_DSN');
        if ($dsn === '') {
            return;
        }
        try {
            $redis   = new \Predis\Client($dsn);
            $taskKey = 'dmc_tasks:' . $requestId;
            $payload = json_encode([
                'requestId' => $requestId,
                'startTime' => $startTime,
                'endTime'   => $endTime,
                'createdAt' => $createdAt,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // 작업 본문 저장 (TTL = 5분 + 60초 여유)
            $redis->setex($taskKey, self::EXPIRES_IN + 60, $payload);

            // 활성 작업 인덱스에 추가 (SET, TTL은 개별 키로 관리)
            $redis->sadd('dmc_tasks:active', [$requestId]);
            // active SET 자체는 넉넉하게 유지 (만료된 항목은 조회 시 SREM)
            $redis->expire('dmc_tasks:active', self::EXPIRES_IN + 3600);
        } catch (\Throwable $e) {
            // 로그만 — Redis 실패는 API 응답에 영향 없음
        }
    }

    /**
     * TCP 소켓으로 WS 프로세스에 combatAggregationStart 전송.
     * MapUpdate::receiveData → broadcastCombatAggregationStart 실행.
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
            $socket->write('combatAggregationStart', [
                'requestId' => $requestId,
                'startTime' => $startTime,
                'endTime'   => $endTime,
            ]);
        } catch (\Throwable $e) {
            // 소켓 전송 실패 — 연결된 클라이언트가 작업을 받지 못함
        }
    }

    private function jsonOut(Base $f3, int $status, array $data): void
    {
        $f3->status($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
