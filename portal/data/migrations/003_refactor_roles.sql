-- Migration: Refactor role system
-- Separates admin privileges from operational roles
--
-- Changes:
-- - Add operational_role column (firefighter/officer for brigade operations)
-- - Add is_admin flag (system administration access)
-- - Migrate existing role data
-- - Keep role column for backwards compatibility

-- Step 1: Add new columns
ALTER TABLE members ADD COLUMN operational_role VARCHAR(20) DEFAULT 'firefighter';
ALTER TABLE members ADD COLUMN is_admin BOOLEAN DEFAULT 0;

-- Step 2: Migrate existing data based on current role
-- Firefighters stay as firefighters, not admins
UPDATE members SET operational_role = 'firefighter', is_admin = 0 WHERE role = 'firefighter';

-- Officers become officers, not admins
UPDATE members SET operational_role = 'officer', is_admin = 0 WHERE role = 'officer';

-- Admins were likely officers with admin access
UPDATE members SET operational_role = 'officer', is_admin = 1 WHERE role = 'admin';

-- Superadmins keep their role (handled specially in code), but set is_admin for consistency
UPDATE members SET operational_role = NULL, is_admin = 1 WHERE role = 'superadmin';

-- Step 3: Create index for common queries
CREATE INDEX IF NOT EXISTS idx_members_operational_role ON members(operational_role);
CREATE INDEX IF NOT EXISTS idx_members_is_admin ON members(is_admin);

-- Note: The 'role' column is kept for backwards compatibility.
-- It will be used for superadmin detection and can be removed in a future migration.
-- For now, code should:
-- - Use operational_role for leave approval checks
-- - Use is_admin for admin panel access
-- - Check role = 'superadmin' for superadmin-specific features
