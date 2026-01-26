-- Migration: Add icad_number and call_type to attendance_records
-- Run this manually: sqlite3 portal.db < migrations/001_add_attendance_icad_calltype.sql

-- Add icad_number column (ICAD/CAD number for callouts)
ALTER TABLE attendance_records ADD COLUMN icad_number VARCHAR(50);

-- Add call_type column (Call type from DLB)
ALTER TABLE attendance_records ADD COLUMN call_type VARCHAR(50);

-- Note: After running this migration, trigger a full attendance sync
-- from the Admin Settings page to populate the new columns.
