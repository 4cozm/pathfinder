<?php

namespace Exodus4D\Pathfinder\Controller\Api;

use Base;
use Exodus4D\Pathfinder\Lib\Config;

class DmcHelper extends \Exodus4D\Pathfinder\Controller\Controller
{
    public function version(Base $f3)
    {
        $body = $f3->get('BODY');
        $data = json_decode($body, true);

        if (!$data || !isset($data['version'], $data['secret'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'code' => 'invalid_payload']);
            return;
        }

        $expectedSecret = Config::getEnvironmentData('DISCORD_TO_PF_HMAC');
        if (trim((string)$data['secret']) !== trim((string)$expectedSecret)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'code' => 'invalid_secret']);
            return;
        }

        $version = trim($data['version']);
        
        // F3 Cache 모듈을 활용 (보통 Redis로 묶임)
        \Cache::instance()->set('dmchelper_min_version', $version, 0); // 0 = ttl 무한

        // 버전 수신 시 Discord 알림 (environment.ini 또는 컨테이너 env의 DISCORD_ALERT_WEBHOOK_URL)
        $webhookUrl = (string) (Config::getEnvironmentData('DISCORD_ALERT_WEBHOOK_URL') ?? '');
        if ($webhookUrl !== '') {
            $embed = [
                'title' => '다클라 헬퍼 버전 등록',
                'description' => '백엔드가 새 버전 정보를 수신했습니다.',
                'color' => 0x57f287,
                'timestamp' => date('c'),
                'fields' => [
                    ['name' => '버전', 'value' => $version, 'inline' => true],
                ],
            ];
            $payload = json_encode(['embeds' => [$embed]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                ],
            ]);
            @file_get_contents($webhookUrl, false, $ctx);
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'version' => $version]);
    }
}
