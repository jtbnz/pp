# Puke Portal - Development & Deployment Plan

This document provides a comprehensive, phased development plan that Claude Code sub-agents can follow to build and test the Puke Portal application.

---

## Table of Contents

1. [Phase 1: Project Setup & Foundation](#phase-1-project-setup--foundation)
2. [Phase 2: Authentication System](#phase-2-authentication-system)
3. [Phase 3: Member Management](#phase-3-member-management)
4. [Phase 4: Calendar System](#phase-4-calendar-system)
5. [Phase 5: Notice Board](#phase-5-notice-board)
6. [Phase 6: Leave Request System](#phase-6-leave-request-system)
7. [Phase 7: DLB Integration](#phase-7-dlb-integration)
8. [Phase 8: PWA & Offline Support](#phase-8-pwa--offline-support)
9. [Phase 9: Notifications](#phase-9-notifications)
10. [Phase 10: Admin Dashboard](#phase-10-admin-dashboard)
11. [Phase 11: Testing & QA](#phase-11-testing--qa)
12. [Phase 12: Deployment](#phase-12-deployment)

---

## Development Environment Setup

### Prerequisites

```bash
# Required
- PHP 8.0+ with extensions: sqlite3, pdo_sqlite, mbstring, json
- Apache with mod_rewrite OR nginx
- Node.js 22+ (for MCP tools and testing)
- Chrome browser (for DevTools MCP)

# Recommended
- VS Code with PHP Intelephense
- SQLite browser (DB Browser for SQLite)
```

### Local Development Server

```bash
# Option 1: PHP built-in server (simplest)
cd public
php -S localhost:8080

# Option 2: Docker (consistent environment)
docker-compose up -d
```

### MCP Tools Available

| Tool | Purpose | Usage |
|------|---------|-------|
| `chrome-devtools` | Visual testing, screenshots, console | Test responsive layouts, debug JS |
| `context7` | Documentation lookup | Query PHP, JS, PWA docs |

---

## Phase 1: Project Setup & Foundation

**Goal:** Create project structure, database, and core routing.

### Tasks

#### 1.1 Create Directory Structure

```
portal/
├── public/
│   ├── index.php
│   ├── .htaccess
│   └── assets/
│       ├── css/
│       │   └── app.css
│       ├── js/
│       │   └── app.js
│       └── icons/
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Middleware/
│   └── Helpers/
├── templates/
│   ├── layouts/
│   │   └── main.php
│   ├── pages/
│   └── partials/
├── config/
│   ├── config.example.php
│   └── .gitkeep
├── data/
│   └── .gitkeep
├── tests/
│   ├── Unit/
│   └── Integration/
├── .gitignore
└── composer.json
```

#### 1.2 Create Core Files

**public/index.php** - Front controller with routing
```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Simple regex-based router (match dlb pattern)
$router = new Router();
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
```

**src/bootstrap.php** - Autoloading and config
```php
<?php
declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoloader
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Load config
$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = new PDO('sqlite:' . $config['database_path']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
```

#### 1.3 Database Schema

**data/schema.sql**
```sql
-- Brigades
CREATE TABLE IF NOT EXISTS brigades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    logo_url VARCHAR(255),
    primary_color VARCHAR(7) DEFAULT '#D32F2F',
    accent_color VARCHAR(7) DEFAULT '#1976D2',
    timezone VARCHAR(50) DEFAULT 'Pacific/Auckland',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Members
CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role VARCHAR(20) NOT NULL DEFAULT 'firefighter',
    rank VARCHAR(20),
    rank_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    access_token VARCHAR(255),
    access_expires DATETIME,
    pin_hash VARCHAR(255),
    push_subscription TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id),
    UNIQUE(brigade_id, email)
);

-- Service periods for honors calculation
CREATE TABLE IF NOT EXISTS service_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Calendar events
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    location VARCHAR(200),
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    all_day BOOLEAN DEFAULT 0,
    recurrence_rule TEXT,
    is_training BOOLEAN DEFAULT 0,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id),
    FOREIGN KEY (created_by) REFERENCES members(id)
);

-- Event exceptions (for recurring events)
CREATE TABLE IF NOT EXISTS event_exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    exception_date DATE NOT NULL,
    is_cancelled BOOLEAN DEFAULT 1,
    replacement_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id)
);

-- Notices
CREATE TABLE IF NOT EXISTS notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    type VARCHAR(20) NOT NULL DEFAULT 'standard',
    display_from DATETIME,
    display_to DATETIME,
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id),
    FOREIGN KEY (author_id) REFERENCES members(id)
);

-- Leave requests
CREATE TABLE IF NOT EXISTS leave_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    training_date DATE NOT NULL,
    reason TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    decided_by INTEGER,
    decided_at DATETIME,
    synced_to_dlb BOOLEAN DEFAULT 0,
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (decided_by) REFERENCES members(id),
    UNIQUE(member_id, training_date)
);

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER,
    member_id INTEGER,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Push subscriptions
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh_key TEXT NOT NULL,
    auth_key TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Public holidays cache
CREATE TABLE IF NOT EXISTS public_holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    region VARCHAR(50) DEFAULT 'auckland',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, region)
);

-- Invite tokens
CREATE TABLE IF NOT EXISTS invite_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'firefighter',
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id),
    FOREIGN KEY (created_by) REFERENCES members(id)
);

-- Sessions
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    member_id INTEGER NOT NULL,
    data TEXT,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_members_brigade ON members(brigade_id);
CREATE INDEX IF NOT EXISTS idx_members_email ON members(email);
CREATE INDEX IF NOT EXISTS idx_events_brigade ON events(brigade_id);
CREATE INDEX IF NOT EXISTS idx_events_start ON events(start_time);
CREATE INDEX IF NOT EXISTS idx_notices_brigade ON notices(brigade_id);
CREATE INDEX IF NOT EXISTS idx_leave_member ON leave_requests(member_id);
CREATE INDEX IF NOT EXISTS idx_leave_date ON leave_requests(training_date);
CREATE INDEX IF NOT EXISTS idx_audit_brigade ON audit_log(brigade_id);
```

#### 1.4 Verification Tests

```bash
# Test 1: PHP version
php -v  # Should be 8.0+

# Test 2: Required extensions
php -m | grep -E "sqlite3|pdo_sqlite|mbstring|json"

# Test 3: Database creation
php -r "new PDO('sqlite:data/portal.db');" && echo "SQLite OK"

# Test 4: Dev server starts
cd public && php -S localhost:8080 &
curl -s http://localhost:8080 | head -5
```

### Sub-Agent Instructions

```
AGENT: Explore
TASK: Verify dlb project structure at https://github.com/jtbnz/dlb matches our planned structure
OUTPUT: Confirmation of alignment or list of differences to address
```

```
AGENT: general-purpose
TASK: Create all Phase 1 files with proper PHP 8 strict typing
OUTPUT: All files created, dev server running on localhost:8080
```

---

## Phase 2: Authentication System

**Goal:** Implement magic link authentication with optional PIN.

### Tasks

#### 2.1 Create Auth Controllers

**src/Controllers/AuthController.php**
- `invite()` - Admin sends invite email
- `verify($token)` - Verify magic link token
- `activate()` - Complete registration (set name, optional PIN)
- `pinLogin()` - Quick PIN re-authentication
- `logout()` - End session

#### 2.2 Create Auth Middleware

**src/Middleware/Auth.php**
- Check session validity
- Refresh session on activity
- Handle expired sessions gracefully

**src/Middleware/RequireRole.php**
- Check user has required role (firefighter, officer, admin, superadmin)
- Return 403 if insufficient permissions

#### 2.3 Create Auth Service

**src/Services/AuthService.php**
```php
<?php
declare(strict_types=1);

class AuthService {
    public function generateInviteToken(): string;
    public function sendInviteEmail(string $email, string $token): bool;
    public function verifyToken(string $token): ?array;
    public function createSession(int $memberId): string;
    public function verifyPin(int $memberId, string $pin): bool;
    public function hashPin(string $pin): string;
}
```

#### 2.4 Email Templates

**templates/emails/invite.php**
```html
Subject: You've been invited to Puke Fire Portal

Hi,

You've been invited to join the Puke Fire Brigade Portal.

Click here to activate your account:
{magic_link}

This link expires in 7 days.

If you didn't expect this email, please ignore it.
```

#### 2.5 Auth Pages

- `templates/pages/auth/login.php` - Email entry for magic link
- `templates/pages/auth/activate.php` - Complete registration form
- `templates/pages/auth/pin.php` - PIN entry for quick login

#### 2.6 Verification Tests

```php
// tests/Unit/AuthServiceTest.php
public function testGenerateInviteToken(): void {
    $service = new AuthService($this->db);
    $token = $service->generateInviteToken();

    $this->assertStringStartsWith('pp_', $token);
    $this->assertEquals(67, strlen($token)); // pp_ + 64 hex chars
}

public function testVerifyValidToken(): void {
    // Create token, verify within 7 days
}

public function testVerifyExpiredToken(): void {
    // Create token, set expires_at to past, verify returns null
}

public function testPinHashing(): void {
    $service = new AuthService($this->db);
    $pin = '123456';
    $hash = $service->hashPin($pin);

    $this->assertTrue($service->verifyPin($memberId, $pin));
    $this->assertFalse($service->verifyPin($memberId, '000000'));
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement AuthController, AuthService, and Auth middleware following dlb patterns
OUTPUT: Working magic link flow - can send invite, click link, activate account
```

```
AGENT: chrome-devtools (via MCP)
TASK: Test auth flow visually
STEPS:
1. Navigate to localhost:8080/auth/login
2. Screenshot the login page
3. Submit test email
4. Check console for errors
5. Screenshot success/error state
```

---

## Phase 3: Member Management

**Goal:** CRUD operations for members with service period tracking.

### Tasks

#### 3.1 Member Model

**src/Models/Member.php**
```php
<?php
declare(strict_types=1);

class Member {
    public function findById(int $id): ?array;
    public function findByEmail(string $email, int $brigadeId): ?array;
    public function findByBrigade(int $brigadeId): array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function deactivate(int $id): bool;
    public function getServicePeriods(int $id): array;
    public function addServicePeriod(int $memberId, array $data): int;
    public function calculateTotalService(int $id): int; // Returns days
}
```

#### 3.2 Member Controller

**src/Controllers/MemberController.php**
- `index()` - List members (admin)
- `show($id)` - Get member details
- `store()` - Create via invite
- `update($id)` - Update member
- `destroy($id)` - Deactivate member
- `servicePeriods($id)` - Manage service periods

#### 3.3 API Endpoints

```
GET    /api/members              # List (admin)
POST   /api/members              # Invite (admin)
GET    /api/members/{id}         # Show
PUT    /api/members/{id}         # Update (admin)
DELETE /api/members/{id}         # Deactivate (admin)
GET    /api/members/{id}/service-periods
POST   /api/members/{id}/service-periods
PUT    /api/members/{id}/service-periods/{pid}
DELETE /api/members/{id}/service-periods/{pid}
```

#### 3.4 Verification Tests

```php
// tests/Unit/MemberModelTest.php
public function testCalculateTotalService(): void {
    // Member with two service periods:
    // 2015-01-01 to 2018-06-30 (3.5 years)
    // 2020-01-01 to null (ongoing)

    $member = new Member($this->db);
    $days = $member->calculateTotalService($memberId);

    // Should be ~3.5 years + time since 2020-01-01
    $this->assertGreaterThan(1277 + 1826, $days); // 3.5y + 5y minimum
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement Member model and controller with service period tracking
OUTPUT: All member CRUD operations working, service calculation accurate
```

---

## Phase 4: Calendar System

**Goal:** Full calendar with recurring events and public holiday integration.

### Tasks

#### 4.1 Event Model

**src/Models/Event.php**
```php
<?php
declare(strict_types=1);

class Event {
    public function findById(int $id): ?array;
    public function findByDateRange(int $brigadeId, string $from, string $to): array;
    public function findTrainingNights(int $brigadeId, string $from, string $to): array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function addException(int $eventId, array $data): int;
    public function expandRecurring(array $event, string $from, string $to): array;
}
```

#### 4.2 Holiday Service

**src/Services/HolidayService.php**
```php
<?php
declare(strict_types=1);

class HolidayService {
    private const HOLIDAYS_API = 'https://date.nager.at/api/v3/PublicHolidays';

    public function fetchHolidays(int $year): array;
    public function getAucklandHolidays(int $year): array;
    public function isPublicHoliday(string $date): bool;
    public function getNextTrainingDate(string $fromDate): string;
    public function generateTrainingDates(string $fromDate, int $months = 12): array;
}
```

#### 4.3 Training Night Logic

```php
// Training night rules:
// 1. Default: Every Monday at 19:00 NZST
// 2. If Monday is Auckland public holiday → move to Tuesday
// 3. Generate 12 months ahead
// 4. Link to dlb muster system

public function generateTrainingDates(string $fromDate, int $months = 12): array {
    $dates = [];
    $current = new DateTime($fromDate, new DateTimeZone('Pacific/Auckland'));
    $end = (clone $current)->modify("+{$months} months");

    // Find first Monday
    while ($current->format('N') !== '1') {
        $current->modify('+1 day');
    }

    while ($current < $end) {
        $date = $current->format('Y-m-d');

        if ($this->isPublicHoliday($date)) {
            // Move to Tuesday
            $trainingDate = (clone $current)->modify('+1 day')->format('Y-m-d');
        } else {
            $trainingDate = $date;
        }

        $dates[] = [
            'date' => $trainingDate,
            'original_date' => $date,
            'is_moved' => $trainingDate !== $date,
            'time' => '19:00:00'
        ];

        $current->modify('+1 week');
    }

    return $dates;
}
```

#### 4.4 ICS Export

**src/Services/IcsService.php**
```php
<?php
declare(strict_types=1);

class IcsService {
    public function generateEvent(array $event): string {
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Puke Portal//EN\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . uniqid('pp-') . "\r\n";
        $ics .= "DTSTART:" . $this->formatDate($event['start_time']) . "\r\n";
        if ($event['end_time']) {
            $ics .= "DTEND:" . $this->formatDate($event['end_time']) . "\r\n";
        }
        $ics .= "SUMMARY:" . $this->escape($event['title']) . "\r\n";
        if ($event['description']) {
            $ics .= "DESCRIPTION:" . $this->escape($event['description']) . "\r\n";
        }
        if ($event['location']) {
            $ics .= "LOCATION:" . $this->escape($event['location']) . "\r\n";
        }
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
}
```

#### 4.5 Calendar UI

**public/assets/js/calendar.js**
- Day/week/month view switching
- Event rendering with color coding
- Touch-friendly navigation
- Pull to refresh
- "Add to Calendar" button integration

#### 4.6 Verification Tests

```php
// tests/Unit/HolidayServiceTest.php
public function testMondayHolidayShiftToTuesday(): void {
    $service = new HolidayService($this->db);

    // Auckland Anniversary 2025 is Monday 27 Jan
    $dates = $service->generateTrainingDates('2025-01-01', 2);

    $jan27 = array_filter($dates, fn($d) => $d['original_date'] === '2025-01-27');
    $jan27 = reset($jan27);

    $this->assertEquals('2025-01-28', $jan27['date']); // Tuesday
    $this->assertTrue($jan27['is_moved']);
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement calendar system with holiday API integration
OUTPUT: Calendar shows events, training nights auto-generated, ICS export works
```

```
AGENT: chrome-devtools (via MCP)
TASK: Test calendar responsiveness
STEPS:
1. Navigate to localhost:8080/calendar
2. Set viewport to 375x667 (iPhone SE)
3. Screenshot month view
4. Switch to week view, screenshot
5. Switch to day view, screenshot
6. Verify touch targets are 44px+
```

---

## Phase 5: Notice Board

**Goal:** Notices with sticky, timed, and urgent types.

### Tasks

#### 5.1 Notice Model

**src/Models/Notice.php**
```php
<?php
declare(strict_types=1);

class Notice {
    public function findActive(int $brigadeId): array;
    public function findById(int $id): ?array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;

    // Active notices query:
    // WHERE (display_from IS NULL OR display_from <= NOW())
    // AND (display_to IS NULL OR display_to >= NOW())
    // ORDER BY type = 'sticky' DESC,
    //          type = 'urgent' DESC,
    //          created_at DESC
}
```

#### 5.2 Notice Controller

**src/Controllers/NoticeController.php**
- `index()` - List active notices
- `show($id)` - Get notice detail
- `store()` - Create notice (admin)
- `update($id)` - Update notice (admin)
- `destroy($id)` - Delete notice (admin)

#### 5.3 Markdown Rendering

**src/Helpers/Markdown.php**
```php
<?php
declare(strict_types=1);

class Markdown {
    public static function render(string $text): string {
        // Simple markdown: **bold**, *italic*, [link](url), lists
        // Sanitize HTML to prevent XSS
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        // Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Links
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);

        // Line breaks
        $text = nl2br($text);

        return $text;
    }
}
```

#### 5.4 Notice UI

**templates/pages/notices/index.php**
- Sticky notices pinned at top
- Urgent notices highlighted (red border)
- Timed notices show expiry countdown
- Pull to refresh

#### 5.5 Verification Tests

```php
// tests/Unit/NoticeModelTest.php
public function testTimedNoticeVisibility(): void {
    $notice = new Notice($this->db);

    // Create timed notice: visible tomorrow only
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $noticeId = $notice->create([
        'brigade_id' => 1,
        'title' => 'Tomorrow Only',
        'content' => 'Test',
        'type' => 'timed',
        'display_from' => $tomorrow . ' 00:00:00',
        'display_to' => $tomorrow . ' 23:59:59',
        'author_id' => 1
    ]);

    // Should not appear in active notices today
    $active = $notice->findActive(1);
    $this->assertNotContains($noticeId, array_column($active, 'id'));
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement notice board with all notice types
OUTPUT: Notices display correctly, sticky at top, timed filter works
```

---

## Phase 6: Leave Request System

**Goal:** Leave requests with officer approval workflow.

### Tasks

#### 6.1 Leave Model

**src/Models/LeaveRequest.php**
```php
<?php
declare(strict_types=1);

class LeaveRequest {
    public function findByMember(int $memberId): array;
    public function findPending(int $brigadeId): array;
    public function findByTrainingDate(string $date, int $brigadeId): array;
    public function create(array $data): int;
    public function approve(int $id, int $approvedBy): bool;
    public function deny(int $id, int $deniedBy): bool;
    public function cancel(int $id): bool;
    public function getUpcomingTrainings(int $memberId, int $limit = 3): array;
}
```

#### 6.2 Leave Controller

**src/Controllers/LeaveController.php**
- `index()` - List leave requests (own or all for officers)
- `store()` - Request leave (max 3 upcoming)
- `approve($id)` - Approve request (officer+)
- `deny($id)` - Deny request (officer+)
- `destroy($id)` - Cancel own pending request

#### 6.3 Leave Request Rules

```php
public function store(Request $request): Response {
    $memberId = $request->user()->id;
    $trainingDate = $request->input('training_date');

    // Validation
    $errors = [];

    // Rule 1: Can only request for future trainings
    if (strtotime($trainingDate) <= time()) {
        $errors[] = 'Cannot request leave for past trainings';
    }

    // Rule 2: Max 3 pending/approved requests
    $existing = $this->leaveModel->findByMember($memberId);
    $activeCount = count(array_filter($existing, fn($r) =>
        $r['status'] !== 'denied' &&
        strtotime($r['training_date']) > time()
    ));

    if ($activeCount >= 3) {
        $errors[] = 'Maximum 3 upcoming leave requests allowed. Contact admin for extended leave.';
    }

    // Rule 3: No duplicate requests
    $duplicate = array_filter($existing, fn($r) =>
        $r['training_date'] === $trainingDate
    );
    if (!empty($duplicate)) {
        $errors[] = 'Leave already requested for this training';
    }

    if (!empty($errors)) {
        return $this->json(['errors' => $errors], 400);
    }

    // Create request
    $id = $this->leaveModel->create([
        'member_id' => $memberId,
        'training_date' => $trainingDate,
        'reason' => $request->input('reason')
    ]);

    // Notify officers
    $this->notificationService->notifyOfficers($brigadeId, 'new_leave_request', [
        'member_name' => $request->user()->name,
        'training_date' => $trainingDate
    ]);

    return $this->json(['id' => $id], 201);
}
```

#### 6.4 Leave UI

**templates/pages/leave/index.php**
- Show upcoming trainings with "Request Leave" button
- Show pending/approved/denied requests
- Officers see pending requests to approve

**templates/pages/leave/pending.php** (Officers)
- List pending requests
- Swipe to approve/deny
- Tap for details

#### 6.5 Verification Tests

```php
// tests/Unit/LeaveRequestTest.php
public function testMaxThreeRequests(): void {
    // Create 3 leave requests
    for ($i = 1; $i <= 3; $i++) {
        $this->leaveModel->create([
            'member_id' => 1,
            'training_date' => date('Y-m-d', strtotime("+{$i} week monday"))
        ]);
    }

    // 4th request should fail
    $this->expectException(ValidationException::class);
    $this->leaveModel->create([
        'member_id' => 1,
        'training_date' => date('Y-m-d', strtotime('+4 week monday'))
    ]);
}

public function testOfficerCanApprove(): void {
    $request = $this->leaveModel->create([
        'member_id' => $firefighterId,
        'training_date' => '2025-02-03'
    ]);

    $result = $this->leaveModel->approve($request, $officerId);

    $this->assertTrue($result);
    $updated = $this->leaveModel->findById($request);
    $this->assertEquals('approved', $updated['status']);
    $this->assertEquals($officerId, $updated['decided_by']);
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement leave request system with approval workflow
OUTPUT: Users can request leave, officers can approve/deny, max 3 enforced
```

```
AGENT: chrome-devtools (via MCP)
TASK: Test leave request flow
STEPS:
1. Login as firefighter
2. Navigate to leave page
3. Screenshot upcoming trainings
4. Request leave for next training
5. Verify pending status shows
6. Login as officer
7. Screenshot pending requests
8. Approve request
9. Verify firefighter sees approved status
```

---

## Phase 7: DLB Integration

**Goal:** Sync with dlb attendance system.

### Prerequisites

The dlb project must implement the API endpoints defined in [dlb-api-integration.md](dlb-api-integration.md).

### Tasks

#### 7.1 DLB Client Service

**src/Services/DlbClient.php**
```php
<?php
declare(strict_types=1);

class DlbClient {
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    public function createMuster(string $date, bool $visible = false): array;
    public function setMusterVisibility(int $musterId, bool $visible): bool;
    public function setAttendanceStatus(int $musterId, int $memberId, string $status): bool;
    public function bulkSetAttendance(int $musterId, array $attendance): array;
    public function getMembers(): array;
    public function getMusterAttendance(int $musterId): array;

    private function request(string $method, string $endpoint, array $data = []): array {
        $ch = curl_init($this->baseUrl . $endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new DlbApiException("DLB API error: " . $response, $httpCode);
        }

        return json_decode($response, true);
    }
}
```

#### 7.2 Sync Service

**src/Services/SyncService.php**
```php
<?php
declare(strict_types=1);

class SyncService {
    public function syncApprovedLeave(LeaveRequest $leave): bool {
        // When leave is approved, set status in dlb
        $muster = $this->dlbClient->findOrCreateMuster($leave->training_date);
        return $this->dlbClient->setAttendanceStatus(
            $muster['id'],
            $leave->member_id,
            'L' // Leave status
        );
    }

    public function createFutureMusters(int $months = 12): array {
        $dates = $this->holidayService->generateTrainingDates(date('Y-m-d'), $months);
        $created = [];

        foreach ($dates as $training) {
            $result = $this->dlbClient->createMuster($training['date'], false);
            $created[] = $result;
        }

        return $created;
    }

    public function revealTodaysMuster(): bool {
        $today = date('Y-m-d');
        $muster = $this->dlbClient->findMusterByDate($today);

        if ($muster && !$muster['visible']) {
            return $this->dlbClient->setMusterVisibility($muster['id'], true);
        }

        return false;
    }
}
```

#### 7.3 Cron Jobs

**cron/reveal_musters.php**
```php
#!/usr/bin/env php
<?php
// Run daily at midnight NZST
// 0 0 * * * php /var/www/portal/cron/reveal_musters.php

declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$syncService = new SyncService($dlbClient, $holidayService);
$result = $syncService->revealTodaysMuster();

if ($result) {
    echo "Revealed muster for " . date('Y-m-d') . "\n";
} else {
    echo "No muster to reveal today\n";
}
```

**cron/generate_musters.php**
```php
#!/usr/bin/env php
<?php
// Run monthly on 1st at 2am NZST
// 0 2 1 * * php /var/www/portal/cron/generate_musters.php

declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

$syncService = new SyncService($dlbClient, $holidayService);
$created = $syncService->createFutureMusters(12);

echo "Created " . count($created) . " musters\n";
```

#### 7.4 Verification Tests

```php
// tests/Integration/DlbSyncTest.php
public function testLeaveApprovalSyncsToDlb(): void {
    // Mock DLB client
    $dlbClient = $this->createMock(DlbClient::class);
    $dlbClient->expects($this->once())
        ->method('setAttendanceStatus')
        ->with(
            $this->equalTo(123), // muster ID
            $this->equalTo(45),  // member ID
            $this->equalTo('L')  // Leave status
        )
        ->willReturn(true);

    $syncService = new SyncService($dlbClient, $this->holidayService);

    // Approve leave
    $leave = new LeaveRequest([
        'id' => 1,
        'member_id' => 45,
        'training_date' => '2025-02-03',
        'status' => 'approved'
    ]);

    $result = $syncService->syncApprovedLeave($leave);
    $this->assertTrue($result);
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement DLB integration client and sync service
OUTPUT: Leave approvals sync to dlb, musters auto-created and revealed
```

---

## Phase 8: PWA & Offline Support

**Goal:** Installable PWA with offline capability.

### Tasks

#### 8.1 Web App Manifest

**public/manifest.json**
```json
{
    "name": "Puke Fire Portal",
    "short_name": "Puke Portal",
    "description": "Fire brigade member portal",
    "start_url": "/",
    "display": "standalone",
    "background_color": "#ffffff",
    "theme_color": "#D32F2F",
    "orientation": "portrait-primary",
    "icons": [
        {
            "src": "/assets/icons/icon-72.png",
            "sizes": "72x72",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-96.png",
            "sizes": "96x96",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-128.png",
            "sizes": "128x128",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-144.png",
            "sizes": "144x144",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-152.png",
            "sizes": "152x152",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any maskable"
        },
        {
            "src": "/assets/icons/icon-384.png",
            "sizes": "384x384",
            "type": "image/png"
        },
        {
            "src": "/assets/icons/icon-512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any maskable"
        }
    ]
}
```

#### 8.2 Service Worker

**public/sw.js**
```javascript
const CACHE_NAME = 'puke-portal-v1';
const OFFLINE_URL = '/offline.html';

const PRECACHE_ASSETS = [
    '/',
    '/offline.html',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/assets/js/calendar.js',
    '/assets/js/notices.js',
    '/assets/js/leave.js',
    '/manifest.json'
];

// Install - precache assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS);
        })
    );
    self.skipWaiting();
});

// Activate - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch - network first, fall back to cache
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // API requests - network only, queue if offline
    if (event.request.url.includes('/api/')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                // Queue for background sync
                return saveToSyncQueue(event.request);
            })
        );
        return;
    }

    // HTML pages - network first
    if (event.request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Cache successful responses
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, clone);
                    });
                    return response;
                })
                .catch(() => {
                    return caches.match(event.request)
                        .then((cached) => cached || caches.match(OFFLINE_URL));
                })
        );
        return;
    }

    // Static assets - cache first
    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached || fetch(event.request).then((response) => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, clone);
                });
                return response;
            });
        })
    );
});

// Background sync for offline actions
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-leave-requests') {
        event.waitUntil(syncLeaveRequests());
    }
});

async function syncLeaveRequests() {
    const db = await openSyncDB();
    const requests = await db.getAll('pending-requests');

    for (const req of requests) {
        try {
            await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body
            });
            await db.delete('pending-requests', req.id);
        } catch (e) {
            console.error('Sync failed:', e);
        }
    }
}
```

#### 8.3 IndexedDB Storage

**public/assets/js/offline-storage.js**
```javascript
const DB_NAME = 'puke-portal';
const DB_VERSION = 1;

class OfflineStorage {
    async open() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                // Store for cached data
                if (!db.objectStoreNames.contains('cache')) {
                    db.createObjectStore('cache', { keyPath: 'key' });
                }

                // Store for pending requests
                if (!db.objectStoreNames.contains('pending-requests')) {
                    const store = db.createObjectStore('pending-requests', {
                        keyPath: 'id',
                        autoIncrement: true
                    });
                    store.createIndex('timestamp', 'timestamp');
                }
            };
        });
    }

    async cacheData(key, data, ttl = 3600) {
        const db = await this.open();
        const tx = db.transaction('cache', 'readwrite');
        const store = tx.objectStore('cache');

        await store.put({
            key,
            data,
            expires: Date.now() + (ttl * 1000)
        });
    }

    async getCached(key) {
        const db = await this.open();
        const tx = db.transaction('cache', 'readonly');
        const store = tx.objectStore('cache');

        const item = await store.get(key);
        if (item && item.expires > Date.now()) {
            return item.data;
        }
        return null;
    }

    async queueRequest(url, method, headers, body) {
        const db = await this.open();
        const tx = db.transaction('pending-requests', 'readwrite');
        const store = tx.objectStore('pending-requests');

        await store.add({
            url,
            method,
            headers,
            body,
            timestamp: Date.now()
        });

        // Register for background sync
        if ('serviceWorker' in navigator && 'sync' in window.registration) {
            await window.registration.sync.register('sync-leave-requests');
        }
    }
}

window.offlineStorage = new OfflineStorage();
```

#### 8.4 Offline UI

**public/offline.html**
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - Puke Portal</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            text-align: center;
            background: #f5f5f5;
        }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { margin: 0 0 10px; color: #333; }
        p { color: #666; margin: 0 0 20px; }
        button {
            background: #D32F2F;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="icon">📡</div>
    <h1>You're Offline</h1>
    <p>Check your internet connection and try again.</p>
    <button onclick="location.reload()">Retry</button>
</body>
</html>
```

#### 8.5 Verification Tests

```javascript
// tests/pwa.test.js (run in browser)
describe('PWA', () => {
    it('has valid manifest', async () => {
        const response = await fetch('/manifest.json');
        const manifest = await response.json();

        expect(manifest.name).toBe('Puke Fire Portal');
        expect(manifest.icons.length).toBeGreaterThan(0);
    });

    it('service worker registers', async () => {
        const registration = await navigator.serviceWorker.register('/sw.js');
        expect(registration.active || registration.installing).toBeTruthy();
    });

    it('works offline', async () => {
        // Cache a page
        await fetch('/');

        // Simulate offline
        // (Note: actual offline test requires network interception)
    });
});
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement PWA with service worker and offline storage
OUTPUT: App installable, works offline for cached content, queues actions
```

```
AGENT: chrome-devtools (via MCP)
TASK: Test PWA installation and offline mode
STEPS:
1. Open Application panel
2. Check manifest is valid
3. Check service worker is registered
4. Run Lighthouse PWA audit
5. Screenshot audit results
6. Toggle offline mode in Network panel
7. Navigate to cached page - should work
8. Navigate to uncached page - should show offline.html
```

---

## Phase 9: Notifications

**Goal:** Email and push notifications.

### Tasks

#### 9.1 Email Service

**src/Services/EmailService.php**
```php
<?php
declare(strict_types=1);

class EmailService {
    private string $driver; // smtp, sendmail, mail
    private array $config;

    public function send(string $to, string $subject, string $html): bool {
        switch ($this->driver) {
            case 'smtp':
                return $this->sendSmtp($to, $subject, $html);
            case 'sendmail':
                return $this->sendSendmail($to, $subject, $html);
            default:
                return mail($to, $subject, $html, $this->getHeaders());
        }
    }

    public function sendInvite(string $email, string $token, string $brigadeName): bool {
        $link = $this->config['app_url'] . '/auth/verify/' . $token;
        $html = $this->renderTemplate('emails/invite', [
            'magic_link' => $link,
            'brigade_name' => $brigadeName,
            'expires' => '7 days'
        ]);
        return $this->send($email, "Invitation to {$brigadeName} Portal", $html);
    }

    public function sendLeaveNotification(array $officers, array $request): bool {
        $html = $this->renderTemplate('emails/leave-request', $request);
        foreach ($officers as $officer) {
            $this->send($officer['email'], 'New Leave Request', $html);
        }
        return true;
    }
}
```

#### 9.2 Push Notification Service

**src/Services/PushService.php**
```php
<?php
declare(strict_types=1);

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushService {
    private WebPush $webPush;

    public function __construct(array $vapidKeys) {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapidKeys['subject'],
                'publicKey' => $vapidKeys['public'],
                'privateKey' => $vapidKeys['private']
            ]
        ]);
    }

    public function subscribe(int $memberId, array $subscription): bool {
        // Store subscription in database
        $stmt = $this->db->prepare('
            INSERT INTO push_subscriptions (member_id, endpoint, p256dh_key, auth_key)
            VALUES (?, ?, ?, ?)
        ');
        return $stmt->execute([
            $memberId,
            $subscription['endpoint'],
            $subscription['keys']['p256dh'],
            $subscription['keys']['auth']
        ]);
    }

    public function send(int $memberId, string $title, string $body, array $data = []): bool {
        $subscriptions = $this->getSubscriptions($memberId);

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh_key'],
                    'auth' => $sub['auth_key']
                ]
            ]);

            $this->webPush->queueNotification(
                $subscription,
                json_encode([
                    'title' => $title,
                    'body' => $body,
                    'data' => $data
                ])
            );
        }

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                // Remove invalid subscription
                $this->removeSubscription($report->getEndpoint());
            }
        }

        return true;
    }

    public function sendToRole(int $brigadeId, string $role, string $title, string $body): void {
        $members = $this->memberModel->findByRole($brigadeId, $role);
        foreach ($members as $member) {
            $this->send($member['id'], $title, $body);
        }
    }
}
```

#### 9.3 Push Client JS

**public/assets/js/push.js**
```javascript
class PushManager {
    constructor(vapidPublicKey) {
        this.vapidPublicKey = vapidPublicKey;
    }

    async subscribe() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.warn('Push notifications not supported');
            return false;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            console.warn('Push permission denied');
            return false;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
        });

        // Send to server
        await fetch('/api/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(subscription)
        });

        return true;
    }

    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');
        const rawData = window.atob(base64);
        return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
    }
}

// In service worker
self.addEventListener('push', (event) => {
    const data = event.data?.json() || {};

    event.waitUntil(
        self.registration.showNotification(data.title || 'Puke Portal', {
            body: data.body,
            icon: '/assets/icons/icon-192.png',
            badge: '/assets/icons/badge-72.png',
            data: data.data,
            actions: data.actions || []
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url || '/';
    event.waitUntil(
        clients.openWindow(url)
    );
});
```

#### 9.4 Notification Triggers

| Event | Email | Push |
|-------|:-----:|:----:|
| New invite | ✓ | |
| Leave requested | ✓ (officers) | ✓ (officers) |
| Leave approved | ✓ | ✓ |
| Leave denied | ✓ | ✓ |
| Urgent notice | ✓ | ✓ |
| Training reminder (24h) | | ✓ |
| Access expiring (30d) | ✓ | |

#### 9.5 Verification Tests

```php
// tests/Unit/PushServiceTest.php
public function testSubscriptionStored(): void {
    $service = new PushService($this->vapidKeys, $this->db);

    $result = $service->subscribe(1, [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/xxx',
        'keys' => [
            'p256dh' => 'BNcRd...',
            'auth' => 'tBHI...'
        ]
    ]);

    $this->assertTrue($result);

    $subscriptions = $service->getSubscriptions(1);
    $this->assertCount(1, $subscriptions);
}
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement email and push notification services
OUTPUT: Notifications sent on all trigger events, push subscription works
```

---

## Phase 10: Admin Dashboard

**Goal:** Admin panel for managing members, events, notices.

### Tasks

#### 10.1 Admin Routes

```php
// Admin routes (require admin role)
$router->group('/admin', function($router) {
    $router->get('/', [AdminController::class, 'dashboard']);
    $router->get('/members', [AdminController::class, 'members']);
    $router->get('/members/invite', [AdminController::class, 'inviteForm']);
    $router->post('/members/invite', [AdminController::class, 'invite']);
    $router->get('/members/{id}', [AdminController::class, 'editMember']);
    $router->put('/members/{id}', [AdminController::class, 'updateMember']);
    $router->get('/events', [AdminController::class, 'events']);
    $router->get('/events/create', [AdminController::class, 'createEventForm']);
    $router->post('/events', [AdminController::class, 'createEvent']);
    $router->get('/notices', [AdminController::class, 'notices']);
    $router->get('/notices/create', [AdminController::class, 'createNoticeForm']);
    $router->post('/notices', [AdminController::class, 'createNotice']);
    $router->get('/leave', [AdminController::class, 'leaveRequests']);
    $router->get('/settings', [AdminController::class, 'settings']);
    $router->put('/settings', [AdminController::class, 'updateSettings']);
});
```

#### 10.2 Dashboard Page

**templates/pages/admin/dashboard.php**
```html
<div class="admin-dashboard">
    <h1>Admin Dashboard</h1>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $activeMembers ?></div>
            <div class="stat-label">Active Members</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $pendingLeave ?></div>
            <div class="stat-label">Pending Leave</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $upcomingEvents ?></div>
            <div class="stat-label">Upcoming Events</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $activeNotices ?></div>
            <div class="stat-label">Active Notices</div>
        </div>
    </div>

    <div class="quick-actions">
        <a href="/admin/members/invite" class="btn btn-primary">Invite Member</a>
        <a href="/admin/events/create" class="btn">Create Event</a>
        <a href="/admin/notices/create" class="btn">Post Notice</a>
    </div>

    <div class="recent-activity">
        <h2>Recent Activity</h2>
        <?php foreach ($recentActivity as $activity): ?>
            <div class="activity-item">
                <span class="activity-action"><?= $activity['action'] ?></span>
                <span class="activity-time"><?= timeAgo($activity['created_at']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
```

#### 10.3 Member Management

- List all members with status filters
- Invite new member form
- Edit member details
- Manage service periods
- Extend/revoke access
- Export member list (CSV)

#### 10.4 Event Management

- Calendar view of all events
- Create one-off or recurring events
- Edit/delete events
- Generate training nights for 12 months
- Mark events as training nights

#### 10.5 Notice Management

- List all notices (including expired)
- Create notice with type selection
- Schedule timed notices
- Edit/delete notices
- Preview markdown rendering

#### 10.6 Settings

- Brigade name and branding
- Email configuration
- DLB API token
- Push notification VAPID keys
- Training night defaults

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Implement admin dashboard with all management pages
OUTPUT: Admins can manage members, events, notices, and settings
```

```
AGENT: chrome-devtools (via MCP)
TASK: Test admin dashboard responsiveness and functionality
STEPS:
1. Login as admin
2. Navigate to /admin
3. Screenshot dashboard
4. Test invite member flow
5. Test create event flow
6. Test create notice flow
7. Verify all forms work on mobile viewport
```

---

## Phase 11: Testing & QA

**Goal:** Comprehensive testing before deployment.

### Tasks

#### 11.1 Unit Tests

```bash
# Run all unit tests
php vendor/bin/phpunit tests/Unit

# Run specific test
php vendor/bin/phpunit tests/Unit/AuthServiceTest.php
```

**Test Coverage Goals:**
- Models: 90%+
- Services: 85%+
- Controllers: 80%+
- Helpers: 95%+

#### 11.2 Integration Tests

```bash
# Run integration tests (requires test database)
php vendor/bin/phpunit tests/Integration
```

**Key Integration Tests:**
- Auth flow (invite → activate → login → logout)
- Leave request flow (request → approve → sync to dlb)
- Calendar generation (holidays detected, trainings shifted)
- Notification delivery (email sent, push queued)

#### 11.3 Browser Testing

```javascript
// tests/e2e/auth.spec.js (Playwright)
import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('magic link flow', async ({ page }) => {
        // Request magic link
        await page.goto('/auth/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.click('button[type="submit"]');

        // Check success message
        await expect(page.locator('.success')).toContainText('Check your email');

        // Simulate clicking magic link
        const token = await getTestToken('test@example.com');
        await page.goto(`/auth/verify/${token}`);

        // Complete activation
        await page.fill('input[name="name"]', 'Test User');
        await page.click('button[type="submit"]');

        // Should be logged in
        await expect(page).toHaveURL('/');
        await expect(page.locator('.user-name')).toContainText('Test User');
    });
});
```

#### 11.4 Performance Testing

```bash
# Lighthouse audit
npx lighthouse http://localhost:8080 --output=html --output-path=./lighthouse.html

# Target scores:
# - Performance: 90+
# - Accessibility: 100
# - Best Practices: 100
# - SEO: 90+
# - PWA: Yes
```

#### 11.5 Security Testing

**Checklist:**
- [ ] SQL injection: All queries use prepared statements
- [ ] XSS: All output escaped with htmlspecialchars()
- [ ] CSRF: Tokens on all forms
- [ ] Session fixation: Session regenerated on login
- [ ] Rate limiting: Login attempts limited
- [ ] HTTPS: Enforced in production
- [ ] Secure cookies: HttpOnly, Secure, SameSite=Strict
- [ ] Input validation: All inputs validated server-side
- [ ] File uploads: None (or properly validated)
- [ ] Error messages: No sensitive info leaked

#### 11.6 Accessibility Testing

```bash
# axe-core audit
npx @axe-core/cli http://localhost:8080

# Manual checks:
# - Keyboard navigation works
# - Screen reader compatible
# - Color contrast meets WCAG AA
# - Touch targets 44px+
# - Focus indicators visible
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Create comprehensive test suite for all components
OUTPUT: Unit, integration, and e2e tests passing
```

```
AGENT: chrome-devtools (via MCP)
TASK: Run accessibility and performance audits
STEPS:
1. Run Lighthouse audit on main pages
2. Screenshot audit results
3. Run accessibility checks
4. List any issues found
5. Test keyboard navigation
```

---

## Phase 12: Deployment

**Goal:** Deploy to production on kiaora.tech.

### Tasks

#### 12.1 Server Setup

```bash
# On kiaora.tech server

# Create directory
sudo mkdir -p /var/www/portal
sudo chown www-data:www-data /var/www/portal

# Clone repository
cd /var/www/portal
git clone https://github.com/jtbnz/pp.git .

# Set permissions
chmod -R 755 /var/www/portal
chmod -R 777 /var/www/portal/data

# Install dependencies (if using composer)
composer install --no-dev --optimize-autoloader
```

#### 12.2 Apache Configuration

**/etc/apache2/sites-available/portal.conf**
```apache
<VirtualHost *:443>
    ServerName portal.kiaora.tech
    DocumentRoot /var/www/portal/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/kiaora.tech/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/kiaora.tech/privkey.pem

    <Directory /var/www/portal/public>
        AllowOverride All
        Require all granted

        # Security headers
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "SAMEORIGIN"
        Header always set X-XSS-Protection "1; mode=block"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
    </Directory>

    # Cache static assets
    <FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/portal_error.log
    CustomLog ${APACHE_LOG_DIR}/portal_access.log combined
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName portal.kiaora.tech
    Redirect permanent / https://portal.kiaora.tech/
</VirtualHost>
```

#### 12.3 Configuration

```bash
# Copy example config
cp config/config.example.php config/config.php

# Edit with production values
nano config/config.php
```

**config/config.php**
```php
<?php
return [
    'app_name' => 'Puke Fire Portal',
    'app_url' => 'https://portal.kiaora.tech',
    'debug' => false,

    'database_path' => __DIR__ . '/../data/portal.db',

    'session' => [
        'timeout' => 86400, // 24 hours
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ],

    'email' => [
        'driver' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'portal@kiaora.tech',
        'password' => 'xxx',
        'from_address' => 'portal@kiaora.tech',
        'from_name' => 'Puke Fire Portal'
    ],

    'push' => [
        'subject' => 'mailto:admin@kiaora.tech',
        'public' => 'BPz...', // Generate with web-push generate-vapid-keys
        'private' => 'xxx'
    ],

    'dlb' => [
        'base_url' => 'https://kiaora.tech/dlb/puke',
        'token' => 'dlb_puke_xxx' // Get from dlb admin
    ],

    'rate_limit' => [
        'max_attempts' => 5,
        'lockout_minutes' => 15
    ]
];
```

#### 12.4 Database Initialization

```bash
# Initialize database
php setup.php

# Create first admin
php artisan admin:create --email=admin@example.com --name="Admin"
```

#### 12.5 Cron Jobs

```bash
# Edit crontab
sudo crontab -e -u www-data

# Add cron jobs
# Reveal musters at midnight NZST (11:00 UTC previous day during NZDT)
0 11 * * * php /var/www/portal/cron/reveal_musters.php >> /var/log/portal/cron.log 2>&1

# Generate musters monthly
0 13 1 * * php /var/www/portal/cron/generate_musters.php >> /var/log/portal/cron.log 2>&1

# Clean expired sessions daily
0 3 * * * php /var/www/portal/cron/cleanup_sessions.php >> /var/log/portal/cron.log 2>&1
```

#### 12.6 SSL Certificate

```bash
# If not already set up for kiaora.tech
sudo certbot --apache -d portal.kiaora.tech
```

#### 12.7 Deployment Script

**deploy.sh**
```bash
#!/bin/bash
set -e

echo "Deploying Puke Portal..."

# Pull latest code
cd /var/www/portal
git pull origin main

# Clear caches
php artisan cache:clear

# Run migrations
php artisan migrate

# Update service worker version
SW_VERSION=$(date +%Y%m%d%H%M%S)
sed -i "s/CACHE_NAME = '.*'/CACHE_NAME = 'puke-portal-v${SW_VERSION}'/" public/sw.js

# Set permissions
chmod -R 755 /var/www/portal
chmod -R 777 /var/www/portal/data

# Restart PHP-FPM (if using)
sudo systemctl reload php8.2-fpm

echo "Deployment complete!"
```

#### 12.8 Monitoring

```bash
# Check error logs
tail -f /var/log/apache2/portal_error.log

# Check application logs
tail -f /var/www/portal/data/logs/app.log

# Monitor disk space
df -h /var/www/portal/data
```

#### 12.9 Backup Strategy

```bash
# Daily database backup
0 2 * * * sqlite3 /var/www/portal/data/portal.db ".backup /var/backups/portal/portal-$(date +\%Y\%m\%d).db"

# Keep 30 days of backups
find /var/backups/portal -name "*.db" -mtime +30 -delete
```

### Sub-Agent Instructions

```
AGENT: general-purpose
TASK: Create deployment script and configuration templates
OUTPUT: deploy.sh ready, config templates created, documentation complete
```

---

## Quick Reference

### Development Commands

```bash
# Start dev server
cd public && php -S localhost:8080

# Run tests
php vendor/bin/phpunit

# Run specific test
php vendor/bin/phpunit tests/Unit/AuthServiceTest.php

# Generate VAPID keys
npx web-push generate-vapid-keys

# Check code style
php vendor/bin/phpcs src/

# Fix code style
php vendor/bin/phpcbf src/
```

### Production Commands

```bash
# Deploy
./deploy.sh

# View logs
tail -f /var/log/apache2/portal_error.log

# Database backup
sqlite3 data/portal.db ".backup backup.db"

# Clear sessions
php artisan session:clear

# Regenerate training nights
php artisan trainings:generate
```

### Useful URLs

| Environment | URL |
|-------------|-----|
| Local Dev | http://localhost:8080 |
| Production | https://portal.kiaora.tech |
| DLB Attendance | https://kiaora.tech/dlb/puke/attendance |
| DLB API | https://kiaora.tech/dlb/puke/api/v1 |

---

## Checklist Summary

### Pre-Development
- [ ] PHP 8.0+ installed with required extensions
- [ ] Chrome DevTools MCP configured
- [ ] Context7 MCP configured (optional)
- [ ] Local dev server running

### Phase Completion
- [ ] Phase 1: Project structure and database
- [ ] Phase 2: Authentication system
- [ ] Phase 3: Member management
- [ ] Phase 4: Calendar system
- [ ] Phase 5: Notice board
- [ ] Phase 6: Leave request system
- [ ] Phase 7: DLB integration
- [ ] Phase 8: PWA & offline support
- [ ] Phase 9: Notifications
- [ ] Phase 10: Admin dashboard
- [ ] Phase 11: Testing & QA
- [ ] Phase 12: Deployment

### Pre-Deployment
- [ ] All tests passing
- [ ] Lighthouse scores meet targets
- [ ] Security checklist complete
- [ ] Accessibility audit passed
- [ ] Production config ready
- [ ] SSL certificate configured
- [ ] Cron jobs scheduled
- [ ] Backup strategy in place
- [ ] Monitoring configured
