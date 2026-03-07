<?php

namespace Exodus4D\Pathfinder\Controller\Api;

use Base;
use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Controller\Controller;

/**
 * dmc_helper 클라이언트용 버전 API.
 *
 * GET  /api/DmcHelper/version → { ok: true, version: "x.y.z" } (클라이언트 업데이트 체크)
 * POST /api/DmcHelper/version → Body: { version, secret } (GitHub Actions 등에서 버전 등록)
 */
class DmcHelper extends Controller
{
    private const VERSION_FILE = '/tmp/dmc_helper_version.txt';
    private const CACHE_KEY   = 'dmchelper_min_version';

    /**
     * GET: 현재 등록된 최신 버전 반환. 클라이언트 AutoUpdater용.
     */
    public function getVersion(Base $f3)
    {
        $version = $this->readVersion();
        $this->jsonOut($f3, 200, ['ok' => true, 'version' => $version]);
    }

    /**
     * POST: 새 버전 등록. 시크릿 인증 후 파일 + Cache(Redis)에 기록.
     */
    public function version(Base $f3)
    {
        $body = $this->readJsonBody();
        $version = is_array($body) ? trim((string)($body['version'] ?? '')) : '';
        $secret  = is_array($body) ? (string)($body['secret'] ?? '') : '';

        if ($version === '') {
            $this->jsonOut($f3, 400, ['ok' => false, 'message' => 'Missing version']);
        }

        $expectSecret = (string)Config::getEnvironmentData('PF_DMC_HELPER_SECRET');
        if ($expectSecret === '') {
            $expectSecret = (string)Config::getEnvironmentData('DISCORD_TO_PF_HMAC');
        }
        if (strlen($expectSecret) < 8 || !hash_equals($expectSecret, $secret)) {
            $this->jsonOut($f3, 401, ['ok' => false, 'message' => 'Invalid secret']);
        }

        // 파일 기록
        @file_put_contents(self::VERSION_FILE, $version . "\n");

        // F3 Cache(Redis)에 기록 — WebSocket 서버가 동일 키로 조회
        try {
            $cache = \Cache::instance();
            if (is_object($cache)) {
                $cache->set(self::CACHE_KEY, $version, 0);
            }
        } catch (\Throwable $e) {
            // Redis 등 없으면 무시
        }

        $this->jsonOut($f3, 200, ['ok' => true, 'version' => $version]);
    }

    private function readVersion(): string
    {
        // 1) 파일
        if (is_readable(self::VERSION_FILE)) {
            $v = trim((string)@file_get_contents(self::VERSION_FILE));
            if ($v !== '') {
                return $v;
            }
        }

        // 2) Cache (Redis)
        try {
            $cache = \Cache::instance();
            if (is_object($cache)) {
                $v = $cache->get(self::CACHE_KEY);
                if ($v !== null && $v !== false && (string)$v !== '') {
                    return trim((string)$v);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '0.0.0';
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
}
