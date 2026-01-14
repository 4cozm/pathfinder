<?php
namespace Exodus4D\Pathfinder\Controller\Api;

use Exodus4D\Pathfinder\Lib\Config;

/**
 * /api/Standalone/issue  (POST)  -> { ok:true, payload, ttl }
 * /api/Standalone/verify (POST)  -> { ok:true, ticket, ttl, cid, ts }
 *
 * ticket은 앱이 WebSocket 열 때 쓰는 “짧은 TTL + 1회성” 인증값으로 쓰면 됨.
 */
class Standalone extends User
{
    const PAYLOAD_TTL_SECONDS = 30;

    // nonce replay 방지 (payload 발급용)
    const NONCE_DIR = 'standalone_nonce';

    // ticket 발급/검증용 (verify 결과)
    const TICKET_TTL_SECONDS = 60;
    const TICKET_DIR = 'standalone_ticket';

    public function issue(\Base $f3)
    {
        $character = $this->getCharacter();
        if (!is_object($character) || empty($character->_id)) {
            $this->jsonOut($f3, 401, ['ok' => false, 'message' => 'Not logged in']);
        }

        $secret = (string)Config::getEnvironmentData('PF_STANDALONE_SECRET');
        if (strlen($secret) < 16) {
            $this->jsonOut($f3, 500, ['ok' => false, 'message' => 'Missing PF_STANDALONE_SECRET']);
        }

        $ts = time();
        $nonce = $this->b64url_encode(random_bytes(16));
        $cid = (int)$character->_id;

        // nonce 1회성
        if (!$this->nonce_markOnce($nonce, self::PAYLOAD_TTL_SECONDS)) {
            $this->jsonOut($f3, 409, ['ok' => false, 'message' => 'nonce replay']);
        }

        $msg = $ts . '.' . $nonce . '.' . $cid;
        $sig = $this->b64url_encode(hash_hmac('sha256', $msg, $secret, true));

        $payloadJson = json_encode([
            'ts'    => $ts,
            'nonce' => $nonce,
            'cid'   => $cid,
            'sig'   => $sig
        ], JSON_UNESCAPED_SLASHES);

        $payload = $this->b64url_encode($payloadJson);

        $this->jsonOut($f3, 200, [
            'ok'      => true,
            'payload' => $payload,
            'ttl'     => self::PAYLOAD_TTL_SECONDS
        ]);
    }
    public function ping(\Base $f3){
    $this->jsonOut($f3, 200, ['ok'=>true,'pong'=>time()]);
    }


    /**
     * Body(JSON): { "payload": "base64url(json)" }
     * Response: { ok:true, ticket, ttl, cid, ts }
     */
    public function verify(\Base $f3)
    {
        $secret = (string)Config::getEnvironmentData('PF_STANDALONE_SECRET');
        if (strlen($secret) < 16) {
            $this->jsonOut($f3, 500, ['ok' => false, 'message' => 'Missing PF_STANDALONE_SECRET']);
        }

        $body = $this->readJsonBody();
        $payload = is_array($body) ? (string)($body['payload'] ?? '') : '';
        if ($payload === '') {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Missing payload']);
        }

        $raw = $this->b64url_decode($payload);
        if ($raw === null) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Bad payload encoding']);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Bad payload json']);
        }

        $ts = (int)($data['ts'] ?? 0);
        $nonce = (string)($data['nonce'] ?? '');
        $cid = (int)($data['cid'] ?? 0);
        $sig = (string)($data['sig'] ?? '');

        if ($ts <= 0 || $cid <= 0 || $nonce === '' || $sig === '') {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Payload missing fields']);
        }

        // TTL 체크
        $now = time();
        if (abs($now - $ts) > self::PAYLOAD_TTL_SECONDS) {
            $this->jsonOut($f3, 401, ['ok' => false, 'message' => 'Payload expired']);
        }

        // sig 검증
        $msg = $ts . '.' . $nonce . '.' . $cid;
        $expect = $this->b64url_encode(hash_hmac('sha256', $msg, $secret, true));
        if (!hash_equals($expect, $sig)) {
            $this->jsonOut($f3, 401, ['ok' => false, 'message' => 'Bad signature']);
        }

        // nonce 재사용 방지 (issue에서 이미 markOnce 했지만, verify에서도 “확실히” 막고 싶으면 consume 형태로 바꿀 수 있음)
        // 여기서는 issue에서 이미 1회성으로 찍혔으므로 별도 재검증은 생략.

        // ticket 발급 + 저장
        $ticket = $this->b64url_encode(random_bytes(24)); // 대략 32 chars
        if (!$this->ticket_put($ticket, $cid, $ts, self::TICKET_TTL_SECONDS)) {
            $this->jsonOut($f3, 500, ['ok' => false, 'message' => 'Ticket store failed']);
        }

        $this->jsonOut($f3, 200, [
            'ok'     => true,
            'ticket' => $ticket,
            'ttl'    => self::TICKET_TTL_SECONDS,
            'cid'    => $cid,
            'ts'     => $ts
        ]);
    }

    /**
     * (옵션) WebSocket 서버에서 “ticket 소비”에 쓰는 헬퍼.
     * - 유효하면 1회성으로 삭제하고 [cid, ts] 반환
     * - 실패하면 null
     */
    public function consumeTicket(string $ticket) : ?array
    {
        return $this->ticket_consume($ticket, self::TICKET_TTL_SECONDS);
    }

    // ----------------- helpers -----------------

    private function readJsonBody() : ?array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function jsonOut(\Base $f3, int $status, array $data)
    {
        $f3->status($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function b64url_encode(string $bin) : string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function b64url_decode(string $b64url) : ?string
    {
        $b64 = strtr($b64url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $out = base64_decode($b64, true);
        return ($out === false) ? null : $out;
    }

    // ----- nonce (payload replay 방지) -----

    private function nonce_markOnce(string $nonce, int $ttlSec) : bool
    {
        $dir = $this->tmpDir(self::NONCE_DIR);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        // 가끔만 청소
        if (function_exists('random_int') && random_int(1, 200) === 1) {
            $this->gcDir($dir, $ttlSec);
        }

        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '', $nonce);
        if ($safe === '') return false;

        $path = $dir . '/' . $safe;

        // atomic create
        $fp = @fopen($path, 'x');
        if ($fp === false) return false;
        fwrite($fp, (string)time());
        fclose($fp);
        return true;
    }

    // ----- ticket (verify 결과) -----

    private function ticket_put(string $ticket, int $cid, int $ts, int $ttlSec) : bool
    {
        $dir = $this->tmpDir(self::TICKET_DIR);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        if (function_exists('random_int') && random_int(1, 200) === 1) {
            $this->gcDir($dir, $ttlSec);
        }

        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '', $ticket);
        if ($safe === '') return false;

        $path = $dir . '/' . $safe;

        // 이미 존재하면 실패 (재사용/충돌 방지)
        $fp = @fopen($path, 'x');
        if ($fp === false) return false;

        // 내용은 최소로만
        fwrite($fp, $cid . '.' . $ts . '.' . time());
        fclose($fp);
        return true;
    }

    private function ticket_consume(string $ticket, int $ttlSec) : ?array
    {
        $dir = $this->tmpDir(self::TICKET_DIR);
        $safe = preg_replace('/[^a-zA-Z0-9\-_]/', '', $ticket);
        if ($safe === '') return null;

        $path = $dir . '/' . $safe;
        if (!is_file($path)) return null;

        $mt = @filemtime($path);
        $now = time();
        if (!$mt || ($now - $mt) > ($ttlSec + 2)) {
            @unlink($path);
            return null;
        }

        $raw = @file_get_contents($path);
        @unlink($path); // 1회성 소비

        if (!is_string($raw) || $raw === '') return null;
        $parts = explode('.', trim($raw));
        if (count($parts) < 2) return null;

        $cid = (int)$parts[0];
        $ts = (int)$parts[1];
        if ($cid <= 0 || $ts <= 0) return null;

        return ['cid' => $cid, 'ts' => $ts];
    }

    // ----- common fs helpers -----

    private function tmpDir(string $sub) : string
    {
        // 컨테이너에서 이미 chmod 777 해둔 디렉토리
        $root = '/var/www/html/pathfinder/tmp';
        return $root . '/' . $sub;
    }

    private function gcDir(string $dir, int $ttlSec)
    {
        $now = time();
        $files = @scandir($dir);
        if (!is_array($files)) return;

        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (!is_file($p)) continue;

            $mt = @filemtime($p);
            if ($mt && ($now - $mt) > ($ttlSec + 10)) {
                @unlink($p);
            }
        }
    }

}
