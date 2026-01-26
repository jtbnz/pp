# Role System Refactor Plan

## Problem Statement

The current role system conflates two separate concerns:
1. **System Administration** - Managing members, settings, sync operations
2. **Operational Authority** - Approving leave requests, operational decisions

This creates conflicts:
- A firefighter who is an admin receives leave request notifications they shouldn't handle
- An admin cannot approve extended leave (CFO rank required) even though they have higher "role"
- The role hierarchy doesn't reflect real-world brigade structure

## Current State

```
role: firefighter | officer | admin | superadmin (hierarchical)
rank: CFO, DCFO, SSO, SO, SFF, QFF, FF, RCFF (seniority/honors)
```

**Problem Examples:**
- User with `role='admin'` and `rank='FF'` (Firefighter) receives leave notifications
- User with `role='officer'` cannot access admin panel even if they need to manage events
- Extended leave approval is rank-based (CFO only), ignoring role hierarchy

## Proposed Solution

Separate concerns into:

```
operational_role: firefighter | officer    -- Brigade operational authority
is_admin: BOOLEAN                          -- System administration access
rank: (unchanged)                          -- Seniority/honors
```

### New Permission Model

| User Type | operational_role | is_admin | Can Approve Leave | Can Access Admin | Receives Leave Notifications |
|-----------|-----------------|----------|-------------------|------------------|------------------------------|
| Firefighter | firefighter | false | NO | NO | NO |
| Admin Firefighter | firefighter | true | NO | YES | NO |
| Officer | officer | false | YES | NO | YES |
| Admin Officer | officer | true | YES | YES | YES |
| CFO | officer | true | YES (including extended) | YES | YES |

### Superadmin Handling

Keep `superadmin` as a special case (system-wide access):
- `operational_role = NULL` or special value
- `is_admin = true` (implicit)
- Full access to everything

## Database Changes

### Migration: 003_refactor_roles.sql

```sql
-- Add new columns
ALTER TABLE members ADD COLUMN operational_role VARCHAR(20) DEFAULT 'firefighter';
ALTER TABLE members ADD COLUMN is_admin BOOLEAN DEFAULT 0;

-- Migrate existing data
UPDATE members SET operational_role = 'officer', is_admin = 1 WHERE role = 'admin';
UPDATE members SET operational_role = 'officer', is_admin = 0 WHERE role = 'officer';
UPDATE members SET operational_role = 'firefighter', is_admin = 0 WHERE role = 'firefighter';
UPDATE members SET operational_role = NULL, is_admin = 1 WHERE role = 'superadmin';

-- Keep role column temporarily for backwards compatibility during migration
-- Can be removed in a future migration after all code is updated
```

### Schema Update

```sql
-- In members table, add constraint
CHECK (operational_role IN ('firefighter', 'officer') OR operational_role IS NULL)
```

## Code Changes Required

### 1. Bootstrap Functions (src/bootstrap.php)

**Update `hasRole()` function:**
```php
function hasRole(string $requiredRole): bool {
    $user = currentUser();
    if (!$user) return false;

    // Superadmin has all roles
    if ($user['role'] === 'superadmin') return true;

    switch ($requiredRole) {
        case 'admin':
            return (bool) $user['is_admin'];
        case 'officer':
            return $user['operational_role'] === 'officer' || (bool) $user['is_admin'];
        case 'firefighter':
            return true; // All authenticated users have firefighter level
        default:
            return false;
    }
}
```

**Add new helper functions:**
```php
function isAdmin(): bool {
    $user = currentUser();
    return $user && ($user['is_admin'] || $user['role'] === 'superadmin');
}

function isOfficer(): bool {
    $user = currentUser();
    return $user && ($user['operational_role'] === 'officer' || $user['role'] === 'superadmin');
}

function canApproveLeave(): bool {
    return isOfficer();
}
```

### 2. Leave Controller (src/Controllers/LeaveController.php)

**Update notification recipients (notifyOfficers):**
```php
// BEFORE:
$stmt = $db->prepare("
    SELECT id, email, name FROM members
    WHERE brigade_id = ? AND status = 'active'
    AND role IN ('officer', 'admin', 'superadmin')
");

// AFTER:
$stmt = $db->prepare("
    SELECT id, email, name FROM members
    WHERE brigade_id = ? AND status = 'active'
    AND (operational_role = 'officer' OR role = 'superadmin')
");
```

**Update pending view access:**
```php
// BEFORE:
if (!hasRole('officer')) { ... }

// AFTER:
if (!isOfficer()) { ... }
```

### 3. Admin Controller (src/Controllers/AdminController.php)

**Update all admin checks:**
```php
// BEFORE:
if (!hasRole('admin')) { ... }

// AFTER:
if (!isAdmin()) { ... }
```

### 4. Member Model (src/Models/Member.php)

**Update validation:**
```php
// BEFORE:
$validRoles = ['firefighter', 'officer', 'admin', 'superadmin'];

// AFTER:
$validOperationalRoles = ['firefighter', 'officer'];
// is_admin is a boolean, no validation needed
```

### 5. Member Management UI

**Update member edit form to show:**
- Operational Role: Firefighter / Officer (dropdown)
- Admin Access: Yes / No (checkbox)
- Rank: (unchanged)

### 6. Poll Controller (src/Controllers/PollController.php)

**Option A: Keep current (anyone can create)**
- No changes needed

**Option B: Restrict to officers+**
```php
public function create(): void {
    if (!isOfficer() && !isAdmin()) {
        $this->flash('error', 'Only officers can create polls');
        header('Location: ' . url('/polls'));
        exit;
    }
    // ...
}
```

**Option C: Add a setting for poll creation permissions**
- Add brigade setting: `poll_creation_role` = 'firefighter' | 'officer' | 'admin'
- Check against this setting

## UI Changes

### Admin Member Edit Page

Current:
```
Role: [Firefighter ▼] [Officer ▼] [Admin ▼]
Rank: [CFO ▼]
```

Proposed:
```
Brigade Role: [Firefighter ▼] [Officer ▼]
Admin Access: [✓] Can manage members, events, and settings
Rank: [CFO ▼]
```

### Member List Display

Add visual indicator for admin status:
- Badge or icon next to admin users
- Filter option: "Show admins only"

## Migration Strategy

1. **Phase 1: Add new columns** (non-breaking)
   - Add `operational_role` and `is_admin` columns
   - Populate from existing `role` values
   - Keep `role` column for backwards compatibility

2. **Phase 2: Update code** (backwards compatible)
   - Update functions to use new columns
   - Keep fallbacks to `role` column

3. **Phase 3: Test thoroughly**
   - Verify all permission checks work correctly
   - Test leave notifications
   - Test admin access

4. **Phase 4: Remove old column** (future)
   - Remove `role` column in future migration
   - Update all remaining references

## Questions for Confirmation

1. **Poll Creation**: Who should be able to create polls?
   - A) Anyone (current behavior)
   - B) Officers and Admins only
   - C) Configurable per brigade

2. **Notice Creation**: Who should be able to create notices?
   - A) Anyone (current behavior)
   - B) Officers and Admins only
   - C) Admins only

3. **Extended Leave Approval**: Should admins be able to approve extended leave?
   - A) Yes, admins can always approve (override CFO requirement)
   - B) No, keep CFO-only requirement
   - C) Admins can approve as fallback if no CFO exists

4. **Backwards Compatibility**: How long to keep the old `role` column?
   - A) Remove immediately after migration
   - B) Keep for one release cycle
   - C) Keep indefinitely for external integrations

## Files to Modify

### High Priority (Core Logic)
- [ ] `portal/data/migrations/003_refactor_roles.sql` (NEW)
- [ ] `portal/data/schema.sql`
- [ ] `portal/src/bootstrap.php` - hasRole, new helpers
- [ ] `portal/src/Controllers/LeaveController.php` - notifications
- [ ] `portal/src/Controllers/AdminController.php` - access checks
- [ ] `portal/src/Models/Member.php` - validation

### Medium Priority (UI)
- [ ] `portal/templates/pages/admin/members/edit.php` - form fields
- [ ] `portal/templates/pages/admin/members/index.php` - list display
- [ ] `portal/src/Controllers/Api/MemberApiController.php` - API responses

### Low Priority (Optional Restrictions)
- [ ] `portal/src/Controllers/PollController.php` - creation restriction
- [ ] `portal/src/Controllers/NoticeController.php` - creation restriction

## Testing Checklist

- [ ] Firefighter cannot approve leave
- [ ] Firefighter + Admin cannot approve leave, CAN access admin
- [ ] Officer can approve leave, cannot access admin
- [ ] Officer + Admin can approve leave AND access admin
- [ ] CFO can approve extended leave
- [ ] Non-CFO officer cannot approve extended leave (unless admin override enabled)
- [ ] Leave notifications only go to officers (operational role)
- [ ] Superadmin has full access to everything
- [ ] Member edit form correctly shows/saves operational_role and is_admin
- [ ] Existing members migrated correctly
