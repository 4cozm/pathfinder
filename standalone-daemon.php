<?php

namespace Exodus4D\Pathfinder;

use Exodus4D\Pathfinder\Lib;
use Exodus4D\Pathfinder\Cron\CharacterUpdate;

session_name('pathfinder_session');

$ROOT = __DIR__;

// composer
$composerAutoloader = $ROOT . '/vendor/autoload.php';
if (!file_exists($composerAutoloader)) {
    die("Couldn't find '$composerAutoloader'. Did you run `composer install`?");
}
require_once $composerAutoloader;

$f3 = \Base::instance();
$f3->set('NAMESPACE', __NAMESPACE__);

// ✅ 핵심: CLI에서는 ROOT가 /var/www/html 로 잡히는 경우가 있어 env/config 경로 해석이 깨짐.
// PF 루트를 강제해서 Config가 /var/www/html/pathfinder/app/environment.ini 를 읽게 만든다.
$f3->set('ROOT', $ROOT);
$f3->set('PATH', '/');
$_SERVER['DOCUMENT_ROOT'] = $ROOT;

// (선택) 현재 루트 확인 로그
error_log('[standalone-daemon][boot] ROOT=' . ($f3->get('ROOT') ?? '(null)') . ' PWD=' . getcwd());

// 1) config.ini 로드
$f3->config($ROOT . '/app/config.ini', true);

// 2) daemon 에러 핸들러: 웹 showError로 흘러가면 Resource::register(null)로 2차 폭발
$f3->set('ONERROR', function ($f3) {
    $err = (array)$f3->get('ERROR');
    error_log('[standalone-daemon][ONERROR] ' . ($err['text'] ?? 'unknown'));
});
$f3->set('UNLOAD', function () {});

// 3) (옵션) PF-ENV 호환 레이어 (안 써도 무해)
$keys = [
    'PF-ENV-SERVER',
    'PF-ENV-DB_PF_DNS',
    'PF-ENV-DB_PF_NAME',
    'PF-ENV-DB_PF_USER',
    'PF-ENV-DB_PF_PASS',
];
foreach ($keys as $k) {
    $v = getenv($k);
    if ($v !== false && (!isset($_SERVER[$k]) || $_SERVER[$k] === '')) {
        $_SERVER[$k] = $v;
    }
}

// 4) Config/Cron 부팅
Lib\Config::instance($f3);

// ✅ ENV sanity check (한 번만 찍어두면 이후 디버깅이 쉬움)
error_log('[standalone-daemon][env] SERVER=' . ($f3->get('ENVIRONMENT.SERVER') ?? ''));
error_log('[standalone-daemon][env] URL=' . ($f3->get('ENVIRONMENT.URL') ?? ''));
error_log('[standalone-daemon][env] DB_PF_DNS=' . ($f3->get('ENVIRONMENT.DB_PF_DNS') ?? ''));
error_log('[standalone-daemon][env] DB_PF_NAME=' . ($f3->get('ENVIRONMENT.DB_PF_NAME') ?? ''));
error_log('[standalone-daemon][env] RAW_ENV_SERVER=' . ($f3->get('ENVIRONMENT.SERVER') ?? ''));
error_log('[standalone-daemon][env] RAW_CONF_SERVER=' . ($f3->get('CONF.SERVER') ?? $f3->get('SERVER') ?? ''));

// ---- DB pool sanity check (debug) ----
try {
    if ($f3->exists('DB')) {
        $dbPool = $f3->get('DB');
        $pf = null;
        if (is_object($dbPool) && method_exists($dbPool, 'getDB')) {
            $pf = $dbPool->getDB('PF');
        }
        error_log('[standalone-daemon][db] pool=' . (is_object($dbPool) ? get_class($dbPool) : gettype($dbPool)) . ' pf=' . (is_object($pf) ? get_class($pf) : 'NULL'));
    } else {
        error_log('[standalone-daemon][db] pool=NULL');
    }
} catch (\Throwable $e) {
    error_log('[standalone-daemon][db] check failed: ' . $e->getMessage());
}

// ✅ alias 주입은 "ENV가 정상인데도 PF alias가 NULL"일 때만 시도
try {
    $dns  = $f3->get('ENVIRONMENT.DB_PF_DNS');
    $name = $f3->get('ENVIRONMENT.DB_PF_NAME');
    $user = $f3->get('ENVIRONMENT.DB_PF_USER');
    $pass = $f3->get('ENVIRONMENT.DB_PF_PASS');

    if ($dns && $name && $user !== null) {
        if (!$f3->exists('DB')) {
            $f3->set('DB', new \DB\Cortex());
        }
        $pool = $f3->get('DB');

        if (is_object($pool) && method_exists($pool, 'getDB') && !$pool->getDB('PF')) {
            $pdo = new \DB\SQL($dns . $name, $user, $pass);

            if (method_exists($pool, 'setDB')) {
                $pool->setDB('PF', $pdo);
            } elseif (method_exists($pool, 'addDB')) {
                $pool->addDB('PF', $pdo);
            } else {
                $f3->set('DB_PF', $pdo);
            }

            error_log('[standalone-daemon][db] PF alias injected');
        }
    } else {
        error_log('[standalone-daemon][db] inject skipped (missing ENV DB values)');
    }
} catch (\Throwable $e) {
    error_log('[standalone-daemon][db] inject failed: ' . $e->getMessage());
}

Lib\Cron::instance();

// ---- daemon loop ----
$interval = (int)($argv[1] ?? 10);
$interval = max(5, $interval);

$job = new CharacterUpdate();

while (true) {
    $tickStart = microtime(true);
    $tickIso = date('c');

    try {
        $job->checkExpiredCombatAggregations($f3);
        $stats = $job->updateStandaloneTrackedLogs($f3);

        $tickMs = (int)round((microtime(true) - $tickStart) * 1000);
        $memMb = (int)round(memory_get_usage(true) / 1024 / 1024);
        $peakMb = (int)round(memory_get_peak_usage(true) / 1024 / 1024);

        error_log(sprintf(
            '[standalone-daemon][%s] tick=%dms files=%d chars=%d processed=%d updated=%d expiredFiles=%d errors=%d job=%dms mem=%dMB peak=%dMB dir=%s',
            $tickIso,
            $tickMs,
            (int)($stats['files'] ?? -1),
            (int)($stats['charsTotal'] ?? -1),
            (int)($stats['charsProcessed'] ?? -1),
            (int)($stats['updated'] ?? -1),
            (int)($stats['expiredFiles'] ?? -1),
            (int)($stats['errors'] ?? -1),
            (int)($stats['elapsedMs'] ?? -1),
            $memMb,
            $peakMb,
            (string)($stats['dir'] ?? '')
        ));
    } catch (\Throwable $e) {
        error_log('[standalone-daemon][EXCEPTION] ' . $e->getMessage());
    }

    sleep($interval);
}
