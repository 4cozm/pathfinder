-- 유저 관계도: 티켓 발급 계정(issuer)별 발견 캐릭터 관계 (pathfinder DB에 1회 실행)
-- 자동: docker-compose up 시 pf-migrate-standalone 서비스가 이 파일을 실행함.

CREATE TABLE IF NOT EXISTS standalone_detect_log (
    issuer_character_id INT UNSIGNED NOT NULL,
    detected_character_id INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (issuer_character_id, detected_character_id),
    INDEX idx_issuer (issuer_character_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
