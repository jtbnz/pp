# Portal Merge Plan — DLB + PP → Unified Portal

## Overview

Merge the **DLB** (Digital Log Book - attendance tracking) and **PP** (Puke Portal - calendar/leave/notices PWA) projects into a single **portal** repository with one shared SQLite database, two independent frontends served via subdomains.

**Result:**
- `dlb.kiaora.tech` → DLB attendance UI (PIN auth)
- `pp.kiaora.tech` → Portal PWA (magic link auth)
- Single `members` table — no duplicate membership lists
- Single SQLite database — no sync layer needed

---

## Architecture

```
portal/                              # New private GitHub repo
├── shared/                          # Shared PHP backend
│   ├── src/
│   │   ├── Models/                  # Unified data models
│   │   │   ├── Member.php           # Single member model for both apps
│   │   │   ├── Brigade.php          # Brigade config (merged)
│   │   │   ├── Callout.php          # DLB callouts
│   │   │   ├── Attendance.php       # DLB attendance records
│   │   │   ├── Truck.php            # DLB trucks
│   │   │   ├── Position.php         # DLB truck positions
│   │   │   ├── Event.php            # PP calendar events
│   │   │   ├── Notice.php           # PP notices
│   │   │   ├── LeaveRequest.php     # PP leave (writes directly to attendance)
│   │   │   ├── Poll.php             # PP polls
│   │   │   └── AuditLog.php         # Unified audit trail
│   │   ├── Services/
│   │   │   ├── Database.php         # SQLite wrapper (from DLB, enhanced)
│   │   │   ├── EmailService.php     # Shared email (merged)
│   │   │   ├── HolidayService.php   # NZ public holidays
│   │   │   └── FenzFetcher.php      # FENZ incident data (from DLB)
│   │   └── Helpers/
│   │       └── helpers.php          # Shared utility functions
│   ├── config/
│   │   ├── config.example.php       # Unified config template
│   │   └── config.php               # (git-ignored) production config
│   ├── data/
│   │   ├── schema.sql               # Merged schema DDL
│   │   └── portal.db                # Single SQLite database (git-ignored)
│   └── bootstrap.php                # Autoloader + config + DB init
│
├── dlb/                             # DLB frontend application
│   ├── public/                      # dlb.kiaora.tech document root
│   │   ├── index.php                # DLB router (requires shared/bootstrap.php)
│   │   ├── sw.js                    # DLB service worker
│   │   ├── manifest.json            # DLB PWA manifest
│   │   ├── .htaccess                # Apache rewrite rules
│   │   └── assets/
│   │       ├── css/app.css
│   │       ├── js/attendance.js
│   │       ├── js/admin.js
│   │       └── images/
│   ├── src/
│   │   ├── Controllers/             # DLB-specific controllers
│   │   │   ├── HomeController.php
│   │   │   ├── AuthController.php   # PIN auth
│   │   │   ├── AttendanceController.php
│   │   │   ├── AdminController.php
│   │   │   ├── SuperAdminController.php
│   │   │   ├── SSEController.php
│   │   │   └── ApiController.php    # External API v1 (token auth)
│   │   └── Middleware/
│   │       ├── PinAuth.php
│   │       ├── AdminAuth.php
│   │       ├── SuperAdminAuth.php
│   │       └── ApiAuth.php
│   └── templates/                   # DLB views
│       ├── layouts/
│       ├── attendance/
│       ├── admin/
│       ├── superadmin/
│       └── email/
│
├── pp/                              # Portal PWA frontend application
│   ├── public/                      # pp.kiaora.tech document root
│   │   ├── index.php                # PP router (requires shared/bootstrap.php)
│   │   ├── sw.js                    # PP service worker
│   │   ├── manifest.json            # PP PWA manifest
│   │   └── assets/
│   │       ├── css/app.css
│   │       ├── js/app.js
│   │       ├── js/calendar.js
│   │       ├── js/leave.js
│   │       ├── js/notices.js
│   │       ├── js/notification-center.js
│   │       ├── js/offline-storage.js
│   │       ├── js/push.js
│   │       ├── js/attendance.js
│   │       └── icons/
│   ├── src/
│   │   ├── Controllers/             # PP-specific controllers
│   │   │   ├── AuthController.php   # Magic link auth
│   │   │   ├── AdminController.php
│   │   │   ├── MemberController.php
│   │   │   ├── CalendarController.php
│   │   │   ├── LeaveController.php
│   │   │   ├── NoticeController.php
│   │   │   └── Api/                 # PP REST API controllers
│   │   ├── Middleware/
│   │   │   ├── Auth.php             # Magic link session auth
│   │   │   ├── Admin.php
│   │   │   ├── Officer.php
│   │   │   └── Csrf.php
│   │   └── Services/
│   │       ├── AuthService.php      # Magic link + PIN logic
│   │       ├── PushService.php      # Web Push (VAPID)
│   │       ├── NotificationService.php
│   │       ├── AttendanceService.php # Stats (reads shared attendance table)
│   │       └── IcsService.php       # Calendar export
│   └── templates/                   # PP views
│       ├── layouts/
│       ├── pages/
│       ├── partials/
│       └── emails/
│
├── import/                          # Data import scripts
│   ├── import-members.php           # CSV member import
│   ├── import-callouts.php          # XLS callout/muster import (from DLB)
│   └── README.md                    # Import instructions
│
├── composer.json                    # PHPSpreadsheet dependency
├── .gitignore
├── CLAUDE.md
└── README.md
```

---

## Phase 1: Repository Setup

### 1.1 Create GitHub Repo
- You create a **private** repo called `portal` at `github.com/jtbnz/portal`
- Clone locally to `/Users/Jon.White/Documents/github/portal`

### 1.2 Initialize Structure
```bash
cd /Users/Jon.White/Documents/github/portal
mkdir -p shared/{src/Models,src/Services,src/Helpers,config,data}
mkdir -p dlb/{public/assets/{css,js,images},src/{Controllers,Middleware},templates/{layouts,attendance,admin,superadmin,email}}
mkdir -p pp/{public/assets/{css,js,icons},src/{Controllers/Api,Middleware,Services},templates/{layouts,pages,partials,emails}}
mkdir -p import
```

### 1.3 Composer Setup
```json
{
    "name": "jtbnz/portal",
    "description": "Puke Fire Brigade Portal - Attendance, Calendar, Leave & Notices",
    "require": {
        "php": ">=8.0",
        "phpoffice/phpspreadsheet": "^1.29"
    },
    "autoload": {
        "psr-4": {
            "Shared\\": "shared/src/",
            "Dlb\\": "dlb/src/",
            "Pp\\": "pp/src/"
        },
        "files": ["shared/src/Helpers/helpers.php"]
    }
}
```

---

## Phase 2: Merged Database Schema

### Design Principles
1. **Single `members` table** — contains fields needed by both apps
2. **`display_name`** = "Rank FirstName LastName" (auto-computed, stored for DLB display)
3. **No `dlb_member_id`** — same row serves both apps
4. **No sync tables** — `sync_logs`, `attendance_sync`, `attendance_records` (PP cache) all removed
5. **DLB `attendance` table is the source of truth** — PP reads it directly
6. **Leave approval writes directly** to `attendance` with `source='portal'`

### Key Schema Changes (vs. current separate DBs)

| Change | Rationale |
|--------|-----------|
| Merged `brigades` table | DLB's `pin_hash`, `admin_*`, `email_recipients` + PP's `training_day`, `timezone`, colors |
| Merged `members` table | DLB's `display_name`, `rank`, `first_name`, `last_name`, `is_active` + PP's `email`, `phone`, `role`, `access_token`, `pin_hash` |
| `leave_requests.dlb_muster_id` → `leave_requests.callout_id` | Direct FK to `callouts`, no external reference |
| `leave_requests.synced_to_dlb` removed | No sync needed — leave approval creates attendance record directly |
| `attendance_records` (PP) removed | PP reads DLB's `attendance` table directly |
| `attendance_sync` removed | No sync process needed |
| `sync_logs` removed | No sync process needed |
| `audit_log` unified | Superset of both schemas — has `member_id`, `callout_id`, `entity_type`, `entity_id` |

### Full Schema

See `shared/data/schema.sql` (will be created in Phase 3).

---

## Phase 3: Database Creation & Data Import

### 3.1 Fresh Database
Start with an empty database created from `schema.sql`. No data migration from old DBs.

### 3.2 Brigade Setup
Insert Puke brigade record with merged config:
```sql
INSERT INTO brigades (name, slug, pin_hash, admin_username, admin_password_hash,
    training_day, training_time, timezone, primary_color, accent_color)
VALUES ('Puke Volunteer Fire Brigade', 'puke', '<bcrypt_hash>', 'admin', '<bcrypt_hash>',
    1, '19:00', 'Pacific/Auckland', '#D32F2F', '#1976D2');
```

### 3.3 Member Import (CSV)
**Input format:** `rank,first_name,last_name,id`

The import script will:
1. Read CSV provided by user
2. For each row:
   - Generate `display_name` as `"Rank FirstName LastName"` (e.g., "CFO John Smith")
   - Set `is_active = 1`
   - Set `status = 'active'`
   - Preserve the provided `id` as the member ID (for callout import matching)
3. No email/auth fields populated yet — those come when members are invited via PP

**Script:** `import/import-members.php`
```
php import/import-members.php puke members.csv [--dry-run]
```

### 3.4 Callout/Muster Import (XLS)
Adapted from existing `dlb/import/import-callouts.php`. Same Excel format:
- Sheet: `AllCalls25` / `AllCalls26`
- Column mapping unchanged (C=Time, E=Date, I=Event Number, M=Event Type, T=Address)
- Member columns (X+) matched by `display_name`
- Members not in CSV are created as `is_active = 0`

**Script:** `import/import-callouts.php`
```
php import/import-callouts.php puke 2025.xlsx --year=2025 [--dry-run]
php import/import-callouts.php puke 2026.xlsx --year=2026 [--dry-run]
```

### 3.5 Import Order
1. Create database from schema.sql
2. Insert brigade + trucks + positions
3. Import members from CSV
4. Import 2025 callouts from XLS
5. Import 2026 callouts from XLS

---

## Phase 4: Shared Backend Code

### 4.1 bootstrap.php
- PSR-4 autoloader (or composer autoload)
- Load `shared/config/config.php`
- Initialize Database connection
- Set timezone to `Pacific/Auckland`
- Both `dlb/public/index.php` and `pp/public/index.php` require this

### 4.2 Shared Models
Models are plain PHP classes that accept the Database instance. Both apps use the same models.

**Member model key method — display name:**
```php
public static function formatDisplayName(string $rank, string $firstName, string $lastName): string
{
    return trim($rank . ' ' . $firstName . ' ' . $lastName);
}
```

### 4.3 Unified Config
Single `shared/config/config.php` with sections for both apps:
```php
return [
    'timezone' => 'Pacific/Auckland',
    'database' => ['path' => __DIR__ . '/../data/portal.db'],

    // DLB-specific
    'dlb' => [
        'url' => 'https://dlb.kiaora.tech',
        'base_path' => '',
        'session' => ['pin_timeout' => 86400, 'admin_timeout' => 1800],
        'super_admin' => ['username' => '...', 'password' => '...'],
        'email' => [...],
    ],

    // PP-specific
    'pp' => [
        'url' => 'https://pp.kiaora.tech',
        'base_path' => '',
        'session' => ['timeout' => 63072000],
        'auth' => ['access_duration_years' => 5, ...],
        'push' => ['enabled' => true, ...],
        'training' => ['default_day' => 1, ...],
        'leave' => ['max_pending' => 3, ...],
    ],

    // Shared
    'email' => [...],  // SMTP config used by both
    'security' => [...],
];
```

---

## Phase 5: Port DLB Application

### 5.1 Copy DLB Code
- Copy `dlb/public/` → `portal/dlb/public/` (index.php, sw.js, manifest.json, assets/)
- Copy `dlb/src/Controllers/` → `portal/dlb/src/Controllers/`
- Copy `dlb/src/Middleware/` → `portal/dlb/src/Middleware/`
- Copy `dlb/templates/` → `portal/dlb/templates/`

### 5.2 Refactor DLB Controllers
- Change `use App\Services\Database` → `use Shared\Services\Database`
- Change `use App\Models\*` → `use Shared\Models\*`
- Update `index.php` to `require_once __DIR__ . '/../../shared/bootstrap.php'`
- Remove `Database::initializeSchema()` call (handled by schema.sql)
- Remove `Database::migrate()` call (no migrations needed for fresh DB)

### 5.3 DLB-Specific Changes
- `AdminController::apiImportMembers()` — update to use shared Member model
- `WebhookService` — **REMOVE** (no more webhook to portal)
- `ApiController` — keep for any future external integrations

### 5.4 Key Behaviour Preserved
- PIN auth flow unchanged
- SSE real-time updates unchanged
- Admin login/management unchanged
- Attendance entry (tap member → tap position) unchanged
- Email on submission unchanged
- FENZ integration unchanged

---

## Phase 6: Port PP Application

### 6.1 Copy PP Code
- Copy `pp/portal/public/` → `portal/pp/public/` (index.php, sw.js, manifest.json, assets/)
- Copy `pp/portal/src/Controllers/` → `portal/pp/src/Controllers/`
- Copy `pp/portal/src/Middleware/` → `portal/pp/src/Middleware/`
- Copy `pp/portal/src/Services/` → `portal/pp/src/Services/` (minus DlbClient, SyncService)
- Copy `pp/portal/templates/` → `portal/pp/templates/`

### 6.2 Remove Sync Layer
Delete entirely:
- `DlbClient.php` — no more HTTP calls to DLB
- `SyncService.php` — no more sync orchestration
- `SyncController.php` — no more sync endpoints
- `WebhookController.php` — no more webhook receiver
- All `/api/sync/*` routes
- `sync_logs`, `attendance_sync`, `attendance_records` references

### 6.3 Refactor Leave Approval
**Before (PP → DLB via HTTP):**
1. Officer approves leave in PP
2. PP calls `DlbClient::setAttendanceStatus()` via HTTP
3. DLB creates attendance record
4. PP marks `synced_to_dlb = 1`

**After (direct database write):**
1. Officer approves leave in PP
2. PP creates/finds callout for that training date
3. PP inserts attendance record directly: `status='L', source='portal'`
4. Done — DLB sees it immediately via shared DB

### 6.4 Refactor Attendance Stats
**Before:** PP cached attendance from DLB into `attendance_records` table
**After:** PP queries the shared `attendance` table directly (same data DLB writes to)

### 6.5 Key Behaviour Preserved
- Magic link auth unchanged
- Calendar/events unchanged
- Notice board unchanged
- Leave request workflow unchanged (just skip the sync step)
- Push notifications unchanged
- Polls unchanged
- Offline/PWA unchanged

---

## Phase 7: Hostinger Deployment

### 7.1 Subdomain Setup
In Hostinger hPanel → **Domains** → **Subdomains**:
1. Create `dlb.kiaora.tech` → point to `portal/dlb/public/`
2. Create `pp.kiaora.tech` → point to `portal/pp/public/`
3. SSL: Let's Encrypt auto-provisioned for subdomains

### 7.2 .htaccess (per public/ directory)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

### 7.3 File Permissions
```bash
chmod 755 shared/data/
chmod 664 shared/data/portal.db
chmod 755 shared/data/logs/   # if logging enabled
```

### 7.4 Config
Copy `shared/config/config.example.php` → `shared/config/config.php` and fill in:
- SMTP credentials
- VAPID keys
- Super admin password
- Brigade PIN hash

### 7.5 Database Initialization
```bash
cd portal
sqlite3 shared/data/portal.db < shared/data/schema.sql
php import/import-members.php puke members.csv
php import/import-callouts.php puke 2025.xlsx --year=2025
php import/import-callouts.php puke 2026.xlsx --year=2026
```

---

## Phase 8: Testing Checklist

### DLB
- [ ] PIN login works at dlb.kiaora.tech
- [ ] Active callout creation
- [ ] Attendance entry (assign member to truck/position)
- [ ] SSE real-time updates between devices
- [ ] Callout submission + email
- [ ] Admin login + member management
- [ ] Member list shows all active members
- [ ] Historical callouts display correctly
- [ ] Leave members (marked via PP) show as 'L' in attendance

### PP
- [ ] Magic link login works at pp.kiaora.tech
- [ ] Calendar shows training nights
- [ ] Leave request submission
- [ ] Leave approval by officer → creates attendance record with status 'L'
- [ ] Leave shows in DLB attendance view
- [ ] Notice board CRUD
- [ ] Push notifications
- [ ] PWA install + offline mode
- [ ] Member attendance stats (reads shared attendance table)
- [ ] Admin panel — member management updates same member record DLB uses

### Cross-App
- [ ] Add member in PP admin → appears in DLB member list
- [ ] Deactivate member in DLB admin → shows inactive in PP
- [ ] Leave approved in PP → DLB shows 'L' for that member on that muster
- [ ] Attendance logged in DLB → PP attendance stats reflect it immediately

---

## What Gets Eliminated

| Removed Component | Lines Saved (approx) | Reason |
|-------------------|----------------------|--------|
| `DlbClient.php` | ~250 | Direct DB access replaces HTTP |
| `SyncService.php` | ~300 | No sync needed |
| `SyncController.php` | ~100 | No sync endpoints |
| `WebhookController.php` (PP) | ~80 | No webhooks needed |
| `WebhookService.php` (DLB) | ~60 | No webhooks needed |
| `attendance_records` table (PP cache) | — | Read shared `attendance` directly |
| `attendance_sync` table | — | No sync metadata |
| `sync_logs` table | — | No sync logging |
| `dlb_member_id` column | — | Same member row |
| `synced_to_dlb` column | — | No sync flag |
| API token management (PP→DLB) | ~100 | No cross-app API calls |
| **Total** | **~900 lines** | |

---

## Risk Mitigation

1. **Keep old repos intact** — Don't delete `jtbnz/pp` or `jtbnz/dlb` until portal is fully validated
2. **Dry-run all imports** before committing data
3. **Test on local PHP dev server** before deploying to Hostinger
4. **Phase the cutover** — Deploy DLB first (simpler app), then PP
5. **Database backups** — Copy `portal.db` before each import step

---

## Timeline Estimate

| Phase | Description | Dependency |
|-------|-------------|------------|
| 1 | Repo setup + structure | You create GitHub repo |
| 2 | Schema.sql written | None |
| 3 | Import scripts + data | You provide CSV + XLS files |
| 4 | Shared backend | Schema complete |
| 5 | Port DLB | Shared backend complete |
| 6 | Port PP | Shared backend complete |
| 7 | Hostinger deploy | All porting complete |
| 8 | Testing | Deploy complete |

Phases 5 and 6 can run in parallel once Phase 4 is done.
