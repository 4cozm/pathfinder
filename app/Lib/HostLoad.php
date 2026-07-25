<?php
/**
 * 호스트 CPU 부하 사실값. CgroupMemory(컨테이너 메모리)의 CPU 짝이다.
 *
 * 컨테이너 안에서도 /proc/loadavg 와 /proc/pressure/cpu 는 호스트 값이 그대로
 * 보인다(네임스페이스되지 않음). 즉 여기서 읽는 숫자는 "이 컨테이너"가 아니라
 * "이 박스 전체"의 부하다 — 표시할 때 반드시 그렇게 이름 붙여야 한다.
 *
 * loadavg 는 코어 수로 나누기 전에는 해석할 수 없다. 2코어 박스에서 load 2.0 은
 * 포화지만 숫자만 보면 낮아 보인다. 그래서 cores() 를 항상 같이 노출한다.
 */

namespace Exodus4D\Pathfinder\Lib;

class HostLoad {

    /**
     * 1/5/15분 load average
     * @return array|null [1분, 5분, 15분] 또는 측정 불가 시 null
     */
    public static function loadAverage() : ?array {
        if(!is_string($raw = @file_get_contents('/proc/loadavg')) || $raw === ''){
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw));
        if(count($parts) < 3){
            return null;
        }

        return [(float)$parts[0], (float)$parts[1], (float)$parts[2]];
    }

    /**
     * 사용 가능한 CPU 코어 수 (loadavg 해석에 반드시 필요)
     * @return int 알 수 없으면 0
     */
    public static function cores() : int {
        if(is_string($raw = @file_get_contents('/proc/cpuinfo'))){
            return max(0, preg_match_all('/^processor\s*:/m', $raw));
        }

        return 0;
    }

    /**
     * CPU 압력(PSI) some avg10/avg60/avg300 — "실행 대기로 지연된 시간의 비율(%)".
     *
     * loadavg 보다 해석이 쉽다. loadavg 는 코어 수와 I/O 대기에 오염되지만
     * PSI some 은 "얼마나 기다렸나"를 직접 준다. 커널이 PSI 를 끄고 빌드됐거나
     * 오래된 커널이면 파일이 없다.
     *
     * @return array|null ['avg10'=>float,'avg60'=>float,'avg300'=>float]
     */
    public static function cpuPressure() : ?array {
        if(!is_string($raw = @file_get_contents('/proc/pressure/cpu'))){
            return null;
        }
        if(!preg_match('/some\s+avg10=([\d.]+)\s+avg60=([\d.]+)\s+avg300=([\d.]+)/', $raw, $m)){
            return null;
        }

        return [
            'avg10'  => (float)$m[1],
            'avg60'  => (float)$m[2],
            'avg300' => (float)$m[3],
        ];
    }
}
