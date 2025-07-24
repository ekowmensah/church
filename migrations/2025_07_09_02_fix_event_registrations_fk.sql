-- Migration: Fix event_registrations.event_id foreign key to reference events(id)

-- 1. Find the current constraint name (optional, for reference)
-- SHOW CREATE TABLE event_registrations;

-- 2. Drop the old/wrong foreign key constraint (replace with actual constraint name if different)
ALTER TABLE event_registrations DROP FOREIGN KEY event_registrations_ibfk_1;

-- 3. Add the correct foreign key constraint
ALTER TABLE event_registrations
  ADD CONSTRAINT fk_event_registrations_event
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE;
