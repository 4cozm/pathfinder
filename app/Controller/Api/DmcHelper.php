<?php

namespace Exodus4D\Pathfinder\Controller\Api;

use Base;

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

        $expectedHtml = getenv('DISCORD_TO_PF_HMAC');
        if (trim($data['secret']) !== trim($expectedHtml)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'code' => 'invalid_secret']);
            return;
        }

        $version = trim($data['version']);
        
        // F3 Cache 모듈을 활용 (보통 Redis로 묶임)
        \Cache::instance()->set('dmchelper_min_version', $version, 0); // 0 = ttl 무한

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'version' => $version]);
    }
}
