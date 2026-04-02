-- 2026_03_21_0103_bible_class_capacity_rules.sql
-- Purpose: enforce max 25 active members per Bible class.

CREATE TABLE IF NOT EXISTS bible_class_rules (
    id INT NOT NULL AUTO_INCREMENT,
    class_id INT NOT NULL,
    max_members INT NOT NULL DEFAULT 25,
    enforce_limit TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bible_class_rules_class (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bible_class_rules (class_id, max_members, enforce_limit)
SELECT id, 25, 1
FROM bible_classes
ON DUPLICATE KEY UPDATE
    max_members = VALUES(max_members),
    enforce_limit = VALUES(enforce_limit);

DROP TRIGGER IF EXISTS trg_members_bible_class_capacity_insert;
DROP TRIGGER IF EXISTS trg_members_bible_class_capacity_update;

DELIMITER $$

CREATE TRIGGER trg_members_bible_class_capacity_insert
BEFORE INSERT ON members
FOR EACH ROW
BEGIN
    DECLARE class_limit INT DEFAULT 25;
    DECLARE should_enforce TINYINT DEFAULT 0;
    DECLARE current_count INT DEFAULT 0;

    IF NEW.class_id IS NOT NULL AND NEW.status = 'active' THEN
        SELECT max_members, enforce_limit
        INTO class_limit, should_enforce
        FROM bible_class_rules
        WHERE class_id = NEW.class_id
        LIMIT 1;

        IF should_enforce = 1 THEN
            SELECT COUNT(*) INTO current_count
            FROM members
            WHERE class_id = NEW.class_id
              AND status = 'active';

            IF current_count >= class_limit THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Bible class membership limit reached (max 25 active members).';
            END IF;
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_members_bible_class_capacity_update
BEFORE UPDATE ON members
FOR EACH ROW
BEGIN
    DECLARE class_limit INT DEFAULT 25;
    DECLARE should_enforce TINYINT DEFAULT 0;
    DECLARE current_count INT DEFAULT 0;

    IF NEW.class_id IS NOT NULL
       AND NEW.status = 'active'
       AND (OLD.class_id <> NEW.class_id OR OLD.status <> NEW.status) THEN

        SELECT max_members, enforce_limit
        INTO class_limit, should_enforce
        FROM bible_class_rules
        WHERE class_id = NEW.class_id
        LIMIT 1;

        IF should_enforce = 1 THEN
            SELECT COUNT(*) INTO current_count
            FROM members
            WHERE class_id = NEW.class_id
              AND status = 'active'
              AND id <> OLD.id;

            IF current_count >= class_limit THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Bible class membership limit reached (max 25 active members).';
            END IF;
        END IF;
    END IF;
END$$

DELIMITER ;
