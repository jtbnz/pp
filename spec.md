# Puke Portal - Comprehensive Specification

## Project Overview

A mobile-first Progressive Web App (PWA) for the Puke Volunteer Fire Brigade. The portal provides calendar management, notice board, leave requests, and integration with the existing attendance system (dlb).

**Live Attendance System:** https://kiaora.tech/dlb/puke/attendance
**Attendance Repo:** https://github.com/jtbnz/dlb

---

## Technology Stack

| Component | Technology | Rationale |
|-----------|------------|-----------|
| Backend | PHP 8.x (strict types) | Match dlb for seamless integration |
| Database | SQLite3 | Portable, no server required, proven with dlb |
| Frontend | Vanilla JS (ES6+) | No build step, fast, works offline |
| Styling | CSS3 with CSS Variables | Dynamic theming, modern animations |
| PWA | Service Worker + IndexedDB | Offline capability, installable |
| Real-time | Server-Sent Events (SSE) | Live updates for notices, approvals |
| Deployment | Same server as dlb (kiaora.tech) | Shared infrastructure |

---

## User Roles & Permissions

### Role Architecture

The system uses a **dual-role architecture** separating operational duties from administrative access:

1. **Operational Role** (`operational_role`): Determines fire service duties
   - `firefighter` - Standard brigade member
   - `officer` - Can approve leave requests (CFO, DCFO, officers, etc.)

2. **Admin Access** (`is_admin`): Boolean flag for administrative capabilities
   - Admins can manage members, events, notices, and settings
   - Admin access is independent of operational role

3. **System Role** (`role`): Legacy field for superadmin designation
   - `firefighter`, `officer`, `admin`, `superadmin`
   - Superadmin grants system-wide access

### Role Examples

| Member | Operational Role | Admin Access | Result |
|--------|-----------------|:------------:|--------|
| Station Officer | officer | No | Can approve leave, no admin panel |
| Admin Secretary | firefighter | Yes | Has admin panel, cannot approve leave |
| Chief Fire Officer | officer | Yes | Full access: approve leave + admin panel |

### Role Capabilities

| Capability | Firefighter | Officer | Admin | Super Admin |
|------------|:-----------:|:-------:|:-----:|:-----------:|
| View calendar/notices | ✓ | ✓ | ✓ | ✓ |
| View own attendance stats | ✓ | ✓ | ✓ | ✓ |
| Request leave (3 trainings) | ✓ | ✓ | ✓ | ✓ |
| Approve leave requests | | ✓ | | ✓ |
| Manage extended leave | | | ✓ | ✓ |
| Create/edit events | | | ✓ | ✓ |
| Manage notices | | | ✓ | ✓ |
| Invite users (magic link) | | | ✓ | ✓ |
| Manage members | | | ✓ | ✓ |
| Manage service history | | | ✓ | ✓ |
| Manage brigades | | | | ✓ |

**Note:** Officers who are also admins can both approve leave AND access admin functions.

---

## Authentication System

### Magic Link Flow

1. Admin enters new member's email in admin panel
2. System generates one-time invite token (valid 7 days)
3. Email sent with magic link: `https://portal.kiaora.tech/auth/{token}`
4. User clicks link, enters their name, sets optional PIN for quick access
5. Access granted for 5 years from activation
6. Admin can regenerate/extend access at any time

### Session Management

- Primary: Session-based with 24-hour timeout
- Optional: 6-digit PIN for quick re-authentication (device-specific)
- Token stored in `localStorage` with refresh mechanism
- Secure: HttpOnly cookies, CSRF protection

---

## Member Data Model

### Core Fields

| Field | Type | Required | Notes |
|-------|------|:--------:|-------|
| id | INTEGER | ✓ | Primary key |
| brigade_id | INTEGER | ✓ | FK to brigades |
| email | VARCHAR(255) | ✓ | Unique per brigade, used for magic links |
| name | VARCHAR(100) | ✓ | Display name |
| phone | VARCHAR(20) | | Mobile for push notifications |
| role | ENUM | ✓ | firefighter, officer, admin, superadmin (legacy) |
| operational_role | ENUM | ✓ | firefighter, officer |
| is_admin | BOOLEAN | ✓ | Admin panel access (default: false) |
| rank | VARCHAR(20) | | CFO, DCFO, SSO, SO, SFF, QFF, FF, RCFF |
| rank_date | DATE | | Date of current rank promotion |
| status | ENUM | ✓ | active, inactive |
| dlb_member_id | INTEGER | | FK to DLB member for sync |
| access_token | VARCHAR(64) | | Session token |
| access_expires | DATETIME | ✓ | 5 years from invite acceptance |
| pin_hash | VARCHAR(255) | | Optional quick-access PIN |
| preferences | JSON | | User preferences (color_blind_mode, etc.) |
| created_at | DATETIME | ✓ | |
| updated_at | DATETIME | ✓ | |

### Service Periods (for honors calculation)

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | Primary key |
| member_id | INTEGER | FK to members |
| start_date | DATE | Service period start |
| end_date | DATE | NULL if currently serving |
| notes | TEXT | Reason for gap if applicable |

**Honors Calculation:** Total service = sum of all (end_date - start_date) periods.

---

## Attendance Status Tracking

Each member has a status per training/muster:

| Code | Status | Description |
|:----:|--------|-------------|
| I | In Attendance | Present at training/muster |
| L | Leave | Approved leave |
| A | Absent | Did not attend, no leave requested |

### Attendance Statistics (Member Profile)

Each member's profile displays rolling 12-month attendance statistics:

#### Training Attendance Gauge
- Percentage of trainings attended (excluding leave days)
- Threshold: 20% (green above, red below)
- Shows: attended / total eligible

#### Callout Attendance Gauge
- Percentage of callouts attended (excluding leave days)
- Threshold: 60% (green above, red below)
- Shows: attended / total eligible

#### Position Statistics
- OIC count and percentage
- Driver count and percentage
- Crew count and percentage

#### Recent Events
- List of last 10 attendance events
- Shows date, event type, status, position, and truck
- Callouts display ICAD number and call type

### Attendance Records Table

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | Primary key |
| member_id | INTEGER | FK to members |
| dlb_muster_id | INTEGER | FK to DLB muster |
| event_date | DATE | Date of event |
| event_type | ENUM | training, callout |
| status | CHAR(1) | I, L, or A |
| position | VARCHAR(50) | OIC, Driver, Crew, etc. |
| truck | VARCHAR(50) | Vehicle identifier |
| icad_number | VARCHAR(50) | ICAD incident number (callouts) |
| call_type | VARCHAR(100) | Type of callout |
| notes | TEXT | Additional notes |
| source | ENUM | dlb, manual |
| created_at | DATETIME | |
| updated_at | DATETIME | |

---

## Calendar System

### Event Types

1. **Training Nights** (recurring)
   - Default: Every Monday 7:00 PM NZST
   - Auto-shifted to Tuesday when Monday is Auckland public holiday
   - Auto-generated 12 months ahead
   - Linked to dlb muster system

2. **One-off Events**
   - Created by admin
   - Supports: title, description, location, start/end time, all-day flag

3. **Recurring Events**
   - Weekly, fortnightly, monthly patterns
   - Exception dates for skipping occurrences

### Auckland Public Holidays Integration

**API Source:** NZ Government Holidays API or static list with annual update

Holidays that shift Monday training to Tuesday:
- New Year's Day (observed)
- Day after New Year's Day (observed)
- Auckland Anniversary Day
- Waitangi Day (observed)
- Good Friday, Easter Monday
- ANZAC Day (observed)
- King's Birthday
- Matariki
- Labour Day
- Christmas Day (observed)
- Boxing Day (observed)

### Calendar Views

- **Day:** Single day with hourly slots
- **Week:** 7-day view with events
- **Month:** Traditional monthly grid
- **Agenda:** List of upcoming events

### Export to Device Calendar

- Generate ICS file for individual events
- "Add to Calendar" button opens native calendar app
- Supports: iOS Calendar, Google Calendar, Outlook

---

## Notice Board

### Notice Types

| Type | Behavior |
|------|----------|
| Standard | Shows in chronological order |
| Sticky | Always appears at top |
| Timed | Visible only between display_from and display_to |
| Urgent | Highlighted styling, push notification sent |

### Notice Fields

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | Primary key |
| title | VARCHAR(200) | Required |
| content | TEXT | Markdown supported |
| type | ENUM | standard, sticky, timed, urgent |
| display_from | DATETIME | NULL = immediately |
| display_to | DATETIME | NULL = indefinitely |
| author_id | INTEGER | FK to members |
| created_at | DATETIME | |

---

## Leave Request System

### Workflow

```
Firefighter                    Officer/Admin               DLB System
     |                              |                          |
     |-- Request leave (1-3) ----->|                          |
     |   (status: pending)         |                          |
     |                             |                          |
     |<---- Email notification ----|                          |
     |                             |                          |
     |                             |-- Approve/Deny --------->|
     |                             |   (status: approved/     |
     |                             |    denied)               |
     |<---- Push notification -----|                          |
     |   (Approved/Denied)         |                          |
     |                             |                          |
     |                             |-- Pre-populate muster -->|
     |                             |   (invisible until day)  |
```

### Leave Request Fields

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | Primary key |
| member_id | INTEGER | FK to members |
| training_date | DATE | Which training |
| reason | TEXT | Optional |
| status | ENUM | pending, approved, denied |
| requested_at | DATETIME | |
| decided_by | INTEGER | FK to members (officer/admin) |
| decided_at | DATETIME | |

### Constraints

- Firefighters: Request leave for up to **3 upcoming trainings**
- Extended leave: Must be entered by Admin
- Officers can approve their own leave (another officer should approve ideally)

---

## DLB Attendance Integration

### Overview

This portal integrates with the existing dlb attendance system to:
1. Pre-populate future muster records with leave statuses
2. Keep member lists synchronized
3. Pull attendance history for statistics and reporting
4. Receive real-time attendance updates via webhook

### Integration Points

| Feature | Direction | Method |
|---------|-----------|--------|
| Create future muster | Portal → DLB | API POST |
| Set member leave status | Portal → DLB | API PUT |
| Reveal muster (make visible) | Portal → DLB | API PUT |
| Sync member list | DLB → Portal | API GET |
| Get attendance history | DLB → Portal | API GET |
| Receive attendance updates | DLB → Portal | Webhook POST |

### Webhook Integration

DLB can push attendance data to the portal via webhook:

**Endpoint:** `POST /api/webhook/dlb/attendance`

**Headers:**
- `X-DLB-Signature`: HMAC-SHA256 signature of payload
- `Content-Type`: application/json

**Payload:**
```json
{
  "muster": {
    "id": 123,
    "call_date": "2024-01-15",
    "call_type": "Training",
    "icad_number": null
  },
  "attendance": [
    {
      "member_id": 45,
      "status": "I",
      "position": "Driver",
      "truck": "Puke 811"
    }
  ]
}
```

### Sync Status Tracking

The portal tracks sync status per brigade:

| Field | Type | Notes |
|-------|------|-------|
| brigade_id | INTEGER | FK to brigades |
| status | ENUM | syncing, completed, failed |
| last_sync_at | DATETIME | Last successful sync |
| sync_from_date | DATE | Start of sync period |
| sync_to_date | DATE | End of sync period |
| error_message | TEXT | Error details if failed |

### Invisible Muster Workflow

1. Portal auto-creates muster in dlb 12 months ahead
2. Muster has `visible: false` flag
3. At midnight on training day, cron job sets `visible: true`
4. Members can then see and use the muster in dlb

**Note:** Detailed API specification in [dlb-api-integration.md](dlb-api-integration.md)

---

## Landing Page

### Layout

```
┌─────────────────────────────────────┐
│  [Brigade Logo]  Puke Fire Portal   │
│  Welcome, {Member Name}             │
├─────────────────────────────────────┤
│  NEXT TRAINING                      │
│  ┌─────────────────────────────────┐│
│  │ Monday 13 Jan 2025, 7:00 PM     ││
│  │ [Request Leave] [Add to Cal]    ││
│  └─────────────────────────────────┘│
├─────────────────────────────────────┤
│  UPCOMING EVENTS                    │
│  • Tue 14 Jan - Equipment check     │
│  • Sat 18 Jan - Community open day  │
│  [View Calendar →]                  │
├─────────────────────────────────────┤
│  NOTICES                            │
│  ┌─────────────────────────────────┐│
│  │ 📌 New hose procedures          ││
│  │ Posted 2 days ago               ││
│  └─────────────────────────────────┘│
│  [View All Notices →]               │
├─────────────────────────────────────┤
│  YOUR LEAVE STATUS                  │
│  • 20 Jan: Pending approval         │
│  • 27 Jan: Approved ✓               │
└─────────────────────────────────────┘
```

---

## Notifications

### Notification Center (In-App)

The notification center provides a persistent notification history accessible from the header bell icon.

#### Features

- **Bell Icon Badge** - Shows unread count (max "99+")
- **Notification Panel** - Dropdown panel on desktop, slide-up sheet on mobile
- **Notification Types:**
  - `system_alert` (Red) - Urgent system notifications
  - `message` (Blue) - General messages and communications
  - `update` (Green) - Status updates and changes
  - `reminder` (Yellow) - Training and event reminders
- **Actions:** Mark as read, mark all as read, delete individual, clear all
- **Preferences** - Users can enable/disable notification types

#### Notification Fields

| Field | Type | Notes |
|-------|------|-------|
| id | INTEGER | Primary key |
| member_id | INTEGER | FK to members |
| brigade_id | INTEGER | FK to brigades |
| type | ENUM | system_alert, message, update, reminder |
| title | VARCHAR(200) | Notification headline |
| body | TEXT | Optional detailed message |
| link | VARCHAR(500) | Optional action URL |
| data | JSON | Additional metadata |
| read_at | DATETIME | NULL if unread |
| created_at | DATETIME | |

### Email Notifications

| Event | Recipients |
|-------|------------|
| New invite / Magic link | Invited member |
| Leave requested | Officers only (not admins unless also officers) |
| Leave approved/denied | Requesting member |
| Urgent notice posted | All active members |
| Access expiring (30 days) | Affected member + Admins |

### Push Notifications (PWA)

| Event | Recipients |
|-------|------------|
| Leave request decision | Requesting member |
| New urgent notice | All active members |
| Training reminder (24h before) | Active members without leave |
| New leave request | Officers only |
| Test notification | Requesting user (creates both push and in-app) |

### Implementation

- Web Push API with VAPID keys
- Service Worker handles push events
- In-app notification center for persistent history
- Polling for badge count updates (60-second interval)
- User preferences stored in `notification_preferences` table

---

## Styling & UX

### Design Principles

- **Mobile-first:** Designed for phone use at station
- **Touch-friendly:** Large tap targets (min 44px)
- **Fast:** No unnecessary animations blocking interaction
- **Accessible:** WCAG 2.1 AA compliant

### Theme

- CSS custom properties for theming
- Brigade can customize primary/accent colors
- Dark mode support (respects system preference)
- Fire service red (#D32F2F) as default accent

### Interactions

- Pull-to-refresh on lists
- Swipe actions (swipe to approve/deny leave)
- Smooth transitions (200-300ms)
- Loading skeletons for async content
- Toast notifications for actions

---

## PWA & Offline Support

### Cached Resources

- App shell (HTML, CSS, JS)
- Member list and roles
- Calendar events (next 30 days)
- Recent notices
- Pending leave requests

### Offline Capabilities

| Feature | Offline Support |
|---------|-----------------|
| View calendar | ✓ (cached events) |
| View notices | ✓ (cached notices) |
| View own leave status | ✓ (cached) |
| Request leave | ✓ (queued, syncs when online) |
| Approve leave | ✓ (queued, syncs when online) |
| Create event | ✗ (requires connection) |

### Sync Strategy

- Background sync API for queued actions
- Conflict resolution: server wins, notify user
- "Offline" indicator in header when disconnected

---

## Database Schema

### Tables

```sql
-- Brigade configuration
brigades (
    id, name, slug, logo_url, primary_color, accent_color,
    timezone, created_at, updated_at
)

-- Members
members (
    id, brigade_id, email, name, phone, role, rank, rank_date,
    status, access_token, access_expires, pin_hash,
    push_subscription, created_at, updated_at
)

-- Service periods for honors
service_periods (
    id, member_id, start_date, end_date, notes, created_at
)

-- Calendar events
events (
    id, brigade_id, title, description, location,
    start_time, end_time, all_day, recurrence_rule,
    is_training, created_by, created_at, updated_at
)

-- Event exceptions (for recurring events)
event_exceptions (
    id, event_id, exception_date, is_cancelled, replacement_date
)

-- Notices
notices (
    id, brigade_id, title, content, type,
    display_from, display_to, author_id, created_at, updated_at
)

-- Leave requests
leave_requests (
    id, member_id, training_date, reason, status,
    requested_at, decided_by, decided_at, synced_to_dlb
)

-- Audit log
audit_log (
    id, brigade_id, member_id, action, details,
    ip_address, user_agent, created_at
)

-- Push subscriptions
push_subscriptions (
    id, member_id, endpoint, p256dh_key, auth_key, created_at
)

-- Public holidays cache
public_holidays (
    id, date, name, region, created_at
)

-- In-app notifications (Issue #26)
notifications (
    id, member_id, brigade_id, type, title, body, link,
    data, read_at, created_at
)

-- Notification preferences
notification_preferences (
    id, member_id, system_alerts, messages, updates, reminders,
    created_at, updated_at
)

-- Attendance records (synced from DLB)
attendance_records (
    id, member_id, dlb_muster_id, event_date, event_type,
    status, position, truck, icad_number, call_type,
    notes, source, created_at, updated_at
)

-- Attendance sync status
attendance_sync (
    id, brigade_id, status, last_sync_at, sync_from_date,
    sync_to_date, error_message
)
```

---

## API Endpoints

### Authentication

```
POST /auth/invite              # Admin sends invite
GET  /auth/verify/{token}      # Verify magic link token
POST /auth/activate            # Complete registration
POST /auth/pin                 # Quick PIN login
POST /auth/logout              # End session
```

### Members

```
GET    /api/members            # List members (admin)
POST   /api/members            # Invite member (admin)
GET    /api/members/{id}       # Get member details
PUT    /api/members/{id}       # Update member (admin)
DELETE /api/members/{id}       # Deactivate member (admin)
GET    /api/members/{id}/service-periods
POST   /api/members/{id}/service-periods
PUT    /api/members/{id}/service-periods/{pid}
DELETE /api/members/{id}/service-periods/{pid}
```

### Calendar

```
GET  /api/events               # List events (with date range)
POST /api/events               # Create event (admin)
GET  /api/events/{id}          # Get event details
PUT  /api/events/{id}          # Update event (admin)
DELETE /api/events/{id}        # Delete event (admin)
GET  /api/events/{id}/ics      # Download ICS file
GET  /api/trainings            # List training nights
POST /api/trainings/generate   # Generate trainings for 12 months
```

### Notices

```
GET    /api/notices            # List active notices
POST   /api/notices            # Create notice (admin)
GET    /api/notices/{id}       # Get notice
PUT    /api/notices/{id}       # Update notice (admin)
DELETE /api/notices/{id}       # Delete notice (admin)
```

### Leave

```
GET  /api/leave                # List leave requests
POST /api/leave                # Request leave
GET  /api/leave/{id}           # Get request details
PUT  /api/leave/{id}/approve   # Approve request (officer+)
PUT  /api/leave/{id}/deny      # Deny request (officer+)
DELETE /api/leave/{id}         # Cancel own request
```

### Sync

```
GET  /api/sync/status          # Get sync status with dlb
POST /api/sync/members         # Sync members with dlb
POST /api/sync/musters         # Sync musters with dlb
```

### Notifications

```
GET    /api/notifications              # List notifications (paginated)
GET    /api/notifications/unread-count # Get unread badge count
PATCH  /api/notifications/{id}/read    # Mark notification as read
POST   /api/notifications/mark-all-read # Mark all as read
DELETE /api/notifications/{id}         # Delete single notification
DELETE /api/notifications/clear        # Clear all notifications
GET    /api/notifications/preferences  # Get notification preferences
PUT    /api/notifications/preferences  # Update notification preferences
```

### Push Notifications

```
GET  /api/push/key             # Get VAPID public key
POST /api/push/subscribe       # Register push subscription
POST /api/push/unsubscribe     # Remove push subscription
POST /api/push/test            # Send test notification (creates push + in-app)
GET  /api/push/status          # Get subscription status
GET  /api/push/debug           # Debug endpoint (dev only)
```

### Attendance

```
GET  /api/members/{id}/attendance        # Get attendance statistics
GET  /api/members/{id}/attendance/recent # Get recent attendance events
POST /api/attendance/sync                # Trigger DLB sync (admin only)
```

### Webhooks

```
POST /api/webhook/dlb/attendance  # Receive attendance data from DLB
```

---

## Directory Structure

```
portal/
├── public/
│   ├── index.php              # Front controller
│   ├── sw.js                  # Service worker
│   ├── manifest.json          # PWA manifest
│   └── assets/
│       ├── css/
│       │   └── app.css
│       ├── js/
│       │   ├── app.js
│       │   ├── calendar.js
│       │   ├── notices.js
│       │   ├── leave.js
│       │   └── push.js
│       └── icons/
├── src/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Middleware/
│   └── Helpers/
├── templates/
│   ├── layouts/
│   ├── pages/
│   └── partials/
├── config/
│   └── config.php
├── data/
│   └── portal.db
└── tests/
```

---

## MCP Configuration

### Chrome DevTools MCP

For testing the PWA in a browser during development.

Add to your Claude Code MCP settings:

```json
{
  "mcpServers": {
    "chrome-devtools": {
      "command": "npx",
      "args": ["-y", "@anthropic/chrome-devtools-mcp"]
    }
  }
}
```

**Usage:** Allows Claude to interact with Chrome DevTools for debugging, inspecting elements, and testing responsive layouts.

### Context7 MCP

For looking up documentation for libraries and frameworks.

```json
{
  "mcpServers": {
    "context7": {
      "command": "npx",
      "args": ["-y", "@anthropic/context7-mcp"],
      "env": {
        "CONTEXT7_API_KEY": "your-api-key-here"
      }
    }
  }
}
```

**Usage:** Query documentation for PHP, JavaScript, PWA APIs, and other technologies used in this project.

---

## Security Considerations

- All passwords/PINs hashed with bcrypt (`password_hash()`)
- CSRF tokens on all forms
- Rate limiting: 5 failed attempts = 15-minute lockout
- Magic link tokens: single-use, 7-day expiry
- Session cookies: HttpOnly, Secure, SameSite=Strict
- Input sanitization with `htmlspecialchars()`
- Parameterized SQL queries (PDO prepared statements)
- Audit logging for sensitive actions

---

## Deployment

### Requirements

- PHP 8.0+ with SQLite3 extension
- Apache with mod_rewrite (or nginx equivalent)
- HTTPS required (for PWA, Push, Secure cookies)
- Writable `/data` directory

### Deployment Steps

1. Clone to `/var/www/portal` on kiaora.tech
2. Point virtual host to `public/` directory
3. Copy `config/config.example.php` to `config/config.php`
4. Configure database path, email settings, VAPID keys
5. Run `php setup.php` to initialize database
6. Create first super admin account

---

## Future Considerations

- Multi-brigade support (already architected)
- Mobile app wrapper (Capacitor/PWABuilder)
- Advanced reporting and analytics dashboard
- Integration with FENZ systems
- Training course tracking and qualifications
- Equipment inventory management
- Incident reporting integration
