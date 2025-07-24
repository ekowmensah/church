-- Migration: Add father_is_member and mother_is_member columns to sunday_school table
ALTER TABLE sunday_school
    ADD COLUMN father_is_member VARCHAR(10) DEFAULT NULL AFTER father_member_id,
    ADD COLUMN mother_is_member VARCHAR(10) DEFAULT NULL AFTER mother_member_id;
