-- Migration: Add deleted_at column to members table for soft deletes
ALTER TABLE members ADD COLUMN deleted_at DATETIME NULL AFTER status;
