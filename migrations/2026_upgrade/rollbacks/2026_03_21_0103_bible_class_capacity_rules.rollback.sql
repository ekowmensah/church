-- rollback: 2026_03_21_0103_bible_class_capacity_rules.sql

DROP TRIGGER IF EXISTS trg_members_bible_class_capacity_insert;
DROP TRIGGER IF EXISTS trg_members_bible_class_capacity_update;
DROP TABLE IF EXISTS bible_class_rules;
