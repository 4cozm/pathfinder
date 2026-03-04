<?php
namespace Exodus4D\Pathfinder\Controller\Api;

use Exodus4D\Pathfinder\Controller;

/**
 * dmc_helper 버전 관리를 위한 API 컨트롤러.
 * GitHub Actions에서 빌드 완료 후 새 버전을 통보(등록)하면,
 * WebSocket 서버와 클라이언트(AutoUpdater)가 이 정보를 참조합니다.
 */
class DmcHelperController extends Controller\Controller {

    private const VERSION_FILE = '/tmp/dmc_helper_version.txt';

    /**
     * POST /api/DmcHelper/version
     * 새 버전을 등록합니다.
     * @param \Base $f3
     */
    public function version($f3) {
        $data = json_decode($f3->get('BODY'), true);
        $version = $data['version'] ?? '';
        $secret = $data['secret'] ?? '';

        // 3.1 Pro 참고: 보안을 위해 GitHub Actions 시크릿과 대조합니다.
        // PF_DMC_HELPER_SECRET 환경변수가 설정되어 있어야 합니다.
        $expectedSecret = getenv('PF_DMC_HELPER_SECRET') ?: getenv('DISCORD_TO_PF_HMAC');

        if (empty($version)) {
            $this->sendJson(['ok' => false, 'error' => 'version_missing'], 400);
            return;
        }

        if (empty($expectedSecret) || $secret !== $expectedSecret) {
            $this->sendJson(['ok' => false, 'error' => 'unauthorized'], 401);
            return;
        }

        // 버전 정보를 파일에 저장 (DB 접근 없이 빠르고 멱등함)
        file_put_contents(self::VERSION_FILE, $version);

        // [3.1 Pro 참고] WebSocket 서버(Ratchet)는 F3의 Cache 인스턴스를 공유합니다.
        // 여기서 캐시를 갱신하면 MapUpdate.php 가 이 값을 즉시 참조하여 구버전을 차단할 수 있습니다.
        \Cache::instance()->set('dmchelper_min_version', $version, 0); // 만료 없음

        $this->sendJson([
            'ok' => true,
            'version' => $version,
            'timestamp' => time()
        ]);
    }


    /**
     * GET /api/DmcHelper/version
     * 현재 등록된 최신 버전을 조회합니다. (AutoUpdater 용)
     */
    public function getVersion($f3) {
        $version = '1.0.0';
        if (file_exists(self::VERSION_FILE)) {
            $version = trim(file_get_contents(self::VERSION_FILE));
        }

        $this->sendJson([
            'ok' => true,
            'version' => $version
        ]);
    }

    private function sendJson($data, $status = 200) {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
    }
}
