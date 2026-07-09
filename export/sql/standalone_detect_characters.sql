-- 유저 관계도: bind 시 수집한 캐릭터 ID 저장 (pathfinder DB에 1회 실행)
-- 자동: docker-compose up 시 pf-migrate-standalone 서비스가 이 파일을 실행함.
-- 수동: mysql -h MYSQL_HOST -u MYSQL_USER -p pathfinder < standalone_detect_characters.sql

CREATE TABLE IF NOT EXISTS standalone_detect_characters (
    character_id INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(255) NULL DEFAULT NULL,
    corporation_id INT UNSIGNED NULL DEFAULT NULL,
    corporation_name VARCHAR(255) NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
