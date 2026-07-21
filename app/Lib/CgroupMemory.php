<?php

namespace Exodus4D\Pathfinder\Lib;

/**
 * 컨테이너(cgroup) 메모리 사용량/한도 리더.
 *
 * 기존 BackpressureManager::getMemoryUsage()는 cgroup v1 경로
 * (/sys/fs/cgroup/memory/memory.usage_in_bytes) 하나만 보고, 없으면 0을 돌려줬다.
 * 운영 호스트는 cgroup v2(cgroup2fs)라 그 경로가 존재하지 않는다.
 * → 항상 0 반환 → 압력 점수의 WEIGHT_MEMORY(30)가 통째로 죽어 있었고,
 *   점수는 구조적으로 70을 넘을 수 없었다.
 *
 * "측정 실패"를 0(=압력 없음)으로 뭉개면 같은 버그가 조용히 재발한다.
 * 그래서 이 클래스는 실패를 0이 아닌 **null**로 돌려주고, 호출부가
 * '압력 없음'과 '측정 불가'를 구분하도록 강제한다.
 *
 * 파일 I/O(readUsageBytes, readLimitBytes)와 순수 로직(parseBytes,
 * normalizeLimitBytes, pressureRatio)을 분리해, 후자를 cgroup 없는
 * 환경에서도 검증할 수 있게 했다. (dev/verify-cgroup-memory.php)
 */
class CgroupMemory {

    /**
     * 현재 사용량 후보 경로 (v2 우선, v1 폴백)
     */
    const USAGE_PATHS = [
        '/sys/fs/cgroup/memory.current',                    // cgroup v2
        '/sys/fs/cgroup/memory/memory.usage_in_bytes',      // cgroup v1
    ];

    /**
     * 한도 후보 경로 (v2 우선, v1 폴백)
     */
    const LIMIT_PATHS = [
        '/sys/fs/cgroup/memory.max',                        // cgroup v2
        '/sys/fs/cgroup/memory/memory.limit_in_bytes',      // cgroup v1
    ];

    /**
     * 상세 통계 후보 경로 (v2 우선, v1 폴백)
     */
    const STAT_PATHS = [
        '/sys/fs/cgroup/memory.stat',                       // cgroup v2
        '/sys/fs/cgroup/memory/memory.stat',                // cgroup v1
    ];

    /**
     * memory.stat 에서 회수 가능한 파일 캐시를 가리키는 필드명 (v2 / v1)
     */
    const INACTIVE_FILE_FIELDS = ['inactive_file', 'total_inactive_file'];

    /**
     * cgroup v1은 한도 미설정 시 0 이나 'max' 가 아니라
     * PHP_INT_MAX 에 근접한 거대 정수(예: 9223372036854771712)를 돌려준다.
     * 이 값을 실제 한도로 믿으면 사용률이 항상 0에 수렴해 신호가 죽는다.
     */
    const UNLIMITED_THRESHOLD_BYTES = 0x7FFFFFFFFFFF0000;

    /**
     * cgroup 파일 문자열 → 바이트.
     *
     * 측정값으로 쓸 수 없는 입력은 전부 null 이다:
     *  - 'max'   : cgroup v2 의 무제한 표기
     *  - 비숫자  : 예기치 못한 포맷
     *  - 음수    : 방어
     *
     * @param string|null $raw
     * @return int|null
     */
    public static function parseBytes(?string $raw) : ?int {
        if(is_null($raw)){
            return null;
        }

        $value = trim($raw);
        if($value === '' || $value === 'max'){
            return null;
        }
        if(!ctype_digit($value)){
            // 음수·소수·문자 혼입 등
            return null;
        }

        return (int)$value;
    }

    /**
     * 한도값 정규화 — v1 의 '무제한 센티널'을 null 로 바꾼다.
     *
     * @param int|null $bytes
     * @return int|null
     */
    public static function normalizeLimitBytes(?int $bytes) : ?int {
        if(is_null($bytes) || $bytes <= 0){
            return null;
        }
        if($bytes >= self::UNLIMITED_THRESHOLD_BYTES){
            return null;
        }
        return $bytes;
    }

    /**
     * 사용률(0.0~) 계산. 어느 한쪽이라도 측정 불가면 null.
     *
     * null 을 0.0 으로 대체하지 않는 것이 핵심이다.
     * 0.0 은 '메모리가 한가하다', null 은 '모른다' 로 의미가 전혀 다르다.
     *
     * @param int|null $usageBytes
     * @param int|null $limitBytes
     * @return float|null
     */
    public static function pressureRatio(?int $usageBytes, ?int $limitBytes) : ?float {
        if(is_null($usageBytes) || is_null($limitBytes) || $limitBytes <= 0){
            return null;
        }
        return $usageBytes / $limitBytes;
    }

    /**
     * memory.stat 에서 특정 필드를 뽑는다. 포맷은 "<key> <value>\n" 의 반복이다.
     *
     * @param string|null $raw
     * @param array $fieldNames 먼저 일치하는 이름을 사용 (v2 이름 → v1 이름 순)
     * @return int|null
     */
    public static function parseStatField(?string $raw, array $fieldNames) : ?int {
        if(is_null($raw) || $raw === ''){
            return null;
        }

        $found = [];
        foreach(explode("\n", $raw) as $line){
            $parts = preg_split('/\s+/', trim($line));
            if(count($parts) < 2){
                continue;
            }
            $value = self::parseBytes($parts[1]);
            if(!is_null($value)){
                $found[$parts[0]] = $value;
            }
        }

        foreach($fieldNames as $name){
            if(array_key_exists($name, $found)){
                return $found[$name];
            }
        }
        return null;
    }

    /**
     * 현재 컨테이너 메모리 사용량 (bytes). 측정 불가 시 null.
     *
     * 0 이하는 '측정 불가'로 본다. 살아있는 컨테이너의 사용량이 0일 수는 없으므로,
     * 0을 그대로 통과시키면 구 버그가 만들던 값(=완전히 한가함)을 그대로 재현하게 된다.
     *
     * @return int|null
     */
    public static function readUsageBytes() : ?int {
        $bytes = self::parseBytes(self::readRaw(self::USAGE_PATHS));
        return (is_null($bytes) || $bytes <= 0) ? null : $bytes;
    }

    /**
     * 컨테이너 메모리 한도 (bytes). 미설정(무제한)/측정 불가 시 null.
     * @return int|null
     */
    public static function readLimitBytes() : ?int {
        return self::normalizeLimitBytes(self::parseBytes(self::readRaw(self::LIMIT_PATHS)));
    }

    /**
     * 회수 가능한 비활성 파일 캐시 (bytes). 측정 불가 시 null.
     * @return int|null
     */
    public static function readInactiveFileBytes() : ?int {
        return self::parseStatField(self::readRaw(self::STAT_PATHS), self::INACTIVE_FILE_FIELDS);
    }

    /**
     * 워킹셋 (bytes) = 사용량 - 회수 가능한 파일 캐시. 측정 불가 시 null.
     *
     * 압력의 분자로 memory.current 를 그대로 쓰면 안 된다.
     * memory.current 는 anon + file(page cache) + kernel 인데, page cache 는
     * 메모리가 부족하면 그냥 회수되지 OOM 을 유발하지 않는다. 이 컨테이너는
     * 로그를 계속 쓰기 때문에 page cache 가 가동시간에 비례해 한도 쪽으로
     * 올라가 거기 머문다 → 멀쩡한 컨테이너가 상시 고압으로 보이게 된다.
     *
     * cAdvisor 의 working set 과 같은 정의다.
     *
     * @return int|null
     */
    public static function readWorkingSetBytes() : ?int {
        $usage = self::readUsageBytes();
        if(is_null($usage)){
            return null;
        }

        $inactiveFile = self::readInactiveFileBytes();
        if(is_null($inactiveFile)){
            // stat 을 못 읽으면 usage 로 폴백한다. 과대평가일 수는 있어도
            // '신호 없음'으로 만드는 것보다는 낫다.
            return $usage;
        }

        return max(0, $usage - $inactiveFile);
    }

    /**
     * 후보 경로 중 **처음으로 존재하는** 것의 내용을 돌려준다.
     *
     * 파싱 실패 시 다음 경로로 넘어가지 않는 것이 중요하다.
     * v2 의 memory.max 는 무제한일 때 'max' 라는 정상적인 비숫자 값을 갖는데,
     * 이걸 '실패'로 보고 v1 경로를 뒤지면 서로 다른 cgroup 계층의 값을 섞게 된다.
     * 폴백 기준은 '파싱 성공 여부'가 아니라 '그 cgroup 버전을 쓰는가'여야 한다.
     *
     * @param array $paths
     * @return string|null
     */
    protected static function readRaw(array $paths) : ?string {
        foreach($paths as $path){
            if(!@is_readable($path)){
                continue;
            }
            $raw = @file_get_contents($path);
            return ($raw === false) ? null : $raw;
        }
        return null;
    }
}
