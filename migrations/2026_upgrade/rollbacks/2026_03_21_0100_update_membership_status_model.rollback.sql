-- rollback: 2026_03_21_0100_update_membership_status_model.sql
-- Note: Distant Member and Invalid are merged back to Invalid Distant Member on rollback.

UPDATE members
SET membership_status = 'Juvenile'
WHERE membership_status = 'Junior Member';

UPDATE members
SET membership_status = 'Invalid Distant Member'
WHERE membership_status IN ('Distant Member', 'Invalid');

UPDATE deleted_members
SET membership_status = 'Juvenile'
WHERE membership_status = 'Junior Member';

UPDATE deleted_members
SET membership_status = 'Invalid Distant Member'
WHERE membership_status IN ('Distant Member', 'Invalid');

ALTER TABLE members
MODIFY COLUMN membership_status ENUM('Full Member', 'Catechumen', 'Adherent', 'Juvenile', 'Invalid Distant Member') DEFAULT NULL;

ALTER TABLE deleted_members
MODIFY COLUMN membership_status ENUM('Full Member', 'Catechumen', 'Adherent', 'Juvenile', 'Invalid Distant Member') DEFAULT NULL;

DROP TABLE IF EXISTS membership_status_migration_audit;
