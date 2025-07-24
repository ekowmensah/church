-- Migration: Add leader_id to bible_classes
ALTER TABLE bible_classes ADD COLUMN leader_id INT NULL, ADD FOREIGN KEY (leader_id) REFERENCES members(id);
