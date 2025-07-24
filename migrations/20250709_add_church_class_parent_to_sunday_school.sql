-- Migration: Add church_id, class_id, father_member_id, mother_member_id to sunday_school table
ALTER TABLE sunday_school
    ADD COLUMN church_id INT DEFAULT NULL AFTER srn,
    ADD COLUMN class_id INT DEFAULT NULL AFTER church_id,
    ADD COLUMN father_member_id INT DEFAULT NULL AFTER mother_occupation,
    ADD COLUMN mother_member_id INT DEFAULT NULL AFTER father_member_id,
    ADD CONSTRAINT fk_sundayschool_church FOREIGN KEY (church_id) REFERENCES churches(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sundayschool_class FOREIGN KEY (class_id) REFERENCES bible_classes(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sundayschool_father FOREIGN KEY (father_member_id) REFERENCES members(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_sundayschool_mother FOREIGN KEY (mother_member_id) REFERENCES members(id) ON DELETE SET NULL;
