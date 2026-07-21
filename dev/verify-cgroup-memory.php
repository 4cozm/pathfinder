<?php
/**
 * CgroupMemory 순수 로직 검증 스크립트.
 *
 * 이 프로젝트에는 테스트 프레임워크가 없다(phpunit 미도입).
 * 클래스 하나 때문에 프레임워크를 들이는 대신, 의존성 없는 assert 스크립트로
 * 파싱/정규화/비율 계산의 경계 조건을 고정한다.
 *
 * 실행 (로컬에 php 없음 → 컨테이너로):
 *   docker run --rm -v "$PWD":/app -w /app php:7.2-cli-alpine \
 *     php dev/verify-cgroup-memory.php
 *
 * 실제 cgroup 값 확인은 운영 컨테이너에서:
 *   docker exec pathfinder php /var/www/html/pathfinder/dev/verify-cgroup-memory.php --live
 */

require_once __DIR__ . '/../app/Lib/CgroupMemory.php';

use Exodus4D\Pathfinder\Lib\CgroupMemory;

$failures = [];
$checks   = 0;

/**
 * @param string $label
 * @param mixed $expected
 * @param mixed $actual
 */
function check(string $label, $expected, $actual) : void {
    global $failures, $checks;
    $checks++;
    if($expected !== $actual){
        $failures[] = sprintf(
            "  FAIL  %s\n        expected: %s\n        actual:   %s",
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

// ─── parseBytes ────────────────────────────────────────────────────────────
// cgroup 파일은 값 끝에 개행이 붙어서 온다. trim 이 빠지면 ctype_digit 이 깨진다.
check('parseBytes: v2 정상값(개행 포함)', 243896320, CgroupMemory::parseBytes("243896320\n"));
check('parseBytes: v1 정상값',            1073741824, CgroupMemory::parseBytes('1073741824'));
check('parseBytes: 앞뒤 공백',             1024,       CgroupMemory::parseBytes("  1024  "));
check('parseBytes: 0',                    0,          CgroupMemory::parseBytes('0'));

// v2 는 한도 미설정 시 숫자가 아니라 'max' 문자열을 돌려준다 — 이걸 (int) 캐스팅하면 0이 된다
check('parseBytes: v2 무제한 max',        null,       CgroupMemory::parseBytes("max\n"));
check('parseBytes: 빈 문자열',             null,       CgroupMemory::parseBytes(''));
check('parseBytes: 공백만',                null,       CgroupMemory::parseBytes("  \n"));
check('parseBytes: null 입력',             null,       CgroupMemory::parseBytes(null));
check('parseBytes: 음수',                  null,       CgroupMemory::parseBytes('-1'));
check('parseBytes: 비숫자',                null,       CgroupMemory::parseBytes('abc'));
check('parseBytes: 소수',                  null,       CgroupMemory::parseBytes('12.5'));

// ─── normalizeLimitBytes ───────────────────────────────────────────────────
check('normalizeLimit: 정상 한도',   1258291200, CgroupMemory::normalizeLimitBytes(1258291200));
check('normalizeLimit: null 통과',   null,       CgroupMemory::normalizeLimitBytes(null));
check('normalizeLimit: 0',           null,       CgroupMemory::normalizeLimitBytes(0));
check('normalizeLimit: 음수',        null,       CgroupMemory::normalizeLimitBytes(-5));

// cgroup v1 은 무제한을 거대 정수로 표현한다. 이걸 한도로 믿으면 사용률이 0에 수렴해 신호가 죽는다
check('normalizeLimit: v1 무제한 센티널', null, CgroupMemory::normalizeLimitBytes(9223372036854771712));

// ─── parseStatField ────────────────────────────────────────────────────────
// memory.stat 은 "<key> <value>" 줄의 반복이다. v2 는 inactive_file,
// v1 은 total_inactive_file 을 쓴다. 이 값을 usage 에서 빼야 워킹셋이 된다.
$statV2 = "anon 104857600\nfile 209715200\nkernel_stack 262144\ninactive_file 157286400\nactive_file 52428800\n";
$statV1 = "cache 209715200\nrss 104857600\ntotal_inactive_file 157286400\ntotal_active_file 52428800\n";

check('parseStatField: v2 inactive_file', 157286400, CgroupMemory::parseStatField($statV2, CgroupMemory::INACTIVE_FILE_FIELDS));
check('parseStatField: v1 total_inactive_file', 157286400, CgroupMemory::parseStatField($statV1, CgroupMemory::INACTIVE_FILE_FIELDS));
check('parseStatField: 다른 필드도 뽑힘', 104857600, CgroupMemory::parseStatField($statV2, ['anon']));
check('parseStatField: 없는 필드', null, CgroupMemory::parseStatField($statV2, ['nonexistent']));
check('parseStatField: 빈 입력', null, CgroupMemory::parseStatField('', CgroupMemory::INACTIVE_FILE_FIELDS));
check('parseStatField: null 입력', null, CgroupMemory::parseStatField(null, CgroupMemory::INACTIVE_FILE_FIELDS));

// v2 이름이 v1 이름보다 우선해야 한다 (둘 다 있는 하이브리드 상황 방어)
$statBoth = "inactive_file 111\ntotal_inactive_file 222\n";
check('parseStatField: v2 이름 우선', 111, CgroupMemory::parseStatField($statBoth, CgroupMemory::INACTIVE_FILE_FIELDS));

// 깨진 줄이 섞여도 나머지는 살아야 한다
$statMessy = "garbage\ninactive_file 333\n\n  \nbroken -1\n";
check('parseStatField: 깨진 줄 혼재', 333, CgroupMemory::parseStatField($statMessy, CgroupMemory::INACTIVE_FILE_FIELDS));

// ─── pressureRatio ─────────────────────────────────────────────────────────
check('pressureRatio: 50%',            0.5,  CgroupMemory::pressureRatio(500, 1000));
check('pressureRatio: 0%',             0.0,  CgroupMemory::pressureRatio(0, 1000));
check('pressureRatio: 100% 초과',      1.25, CgroupMemory::pressureRatio(1250, 1000));

// 측정 불가는 0.0(=한가함)이 아니라 null(=모름)이어야 한다. 이 구분이 이번 수정의 핵심이다
check('pressureRatio: 사용량 측정불가', null, CgroupMemory::pressureRatio(null, 1000));
check('pressureRatio: 한도 측정불가',   null, CgroupMemory::pressureRatio(500, null));
check('pressureRatio: 둘 다 불가',      null, CgroupMemory::pressureRatio(null, null));
check('pressureRatio: 한도 0',          null, CgroupMemory::pressureRatio(500, 0));

// ─── 결과 ──────────────────────────────────────────────────────────────────
echo "\n";
if(empty($failures)){
    echo "OK  — {$checks} checks passed\n";
} else {
    echo "FAILED — " . count($failures) . " of {$checks} checks\n\n";
    echo implode("\n", $failures) . "\n";
}

// --live: 실제 호스트의 cgroup 값을 읽어본다 (경로 폴백이 실환경에서 동작하는지)
if(in_array('--live', $argv, true)){
    $usage = CgroupMemory::readUsageBytes();
    $limit = CgroupMemory::readLimitBytes();
    $ratio = CgroupMemory::pressureRatio($usage, $limit);

    echo "\n--- live cgroup read ---\n";
    printf("usage : %s\n", is_null($usage) ? 'UNAVAILABLE' : number_format($usage) . ' bytes (' . round($usage / 1048576, 1) . ' MiB)');
    printf("limit : %s\n", is_null($limit) ? 'UNAVAILABLE / unlimited' : number_format($limit) . ' bytes (' . round($limit / 1048576, 1) . ' MiB)');
    printf("ratio : %s\n", is_null($ratio) ? 'N/A' : round($ratio * 100, 1) . '%');
}

exit(empty($failures) ? 0 : 1);
