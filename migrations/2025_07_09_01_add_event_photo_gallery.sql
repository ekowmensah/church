-- Migration: Add event photo and gallery columns to events table
ALTER TABLE `events`
  ADD COLUMN `photo` VARCHAR(255) NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `gallery` TEXT NULL DEFAULT NULL AFTER `photo`;
