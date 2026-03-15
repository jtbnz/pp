-- ============================================================================
-- PORTAL MERGED DATABASE SCHEMA
-- Combines DLB (attendance) and PP (calendar/leave/notices) into one database
-- SQLite3 | Timezone: Pacific/Auckland (NZST/NZDT)
-- ============================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- ============================================================================
-- BRIGADES (merged from both DLB and PP)
-- ============================================================================

CREATE TABLE IF NOT EXISTS brigades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Identity
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,                    -- URL slug: dlb.kiaora.tech/{slug}
    logo_url TEXT,

    -- DLB auth (brigade-level PIN + admin credentials)
    pin_hash TEXT NOT NULL,                       -- bcrypt hash of brigade PIN
    admin_username TEXT NOT NULL,
    admin_password_hash TEXT NOT NULL,

    -- DLB settings
    email_recipients TEXT DEFAULT '[]',           -- JSON array of emails for callout submission
    include_non_attendees INTEGER DEFAULT 0,      -- Include absent members in email
    member_order TEXT DEFAULT 'rank_name',         -- rank_name, rank_joindate, alphabetical
    require_submitter_name INTEGER DEFAULT 1,      -- Require name when submitting callout
    region INTEGER DEFAULT 1,                      -- FENZ region code

    -- PP settings
    training_day INTEGER DEFAULT 1,               -- Day of week: 1=Monday, 7=Sunday
    training_time TEXT DEFAULT '19:00',            -- 24hr format
    timezone TEXT DEFAULT 'Pacific/Auckland',
    primary_color TEXT DEFAULT '#D32F2F',          -- Fire service red
    accent_color TEXT DEFAULT '#1976D2',           -- Blue accent

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- MEMBERS (single table for both apps)
-- ============================================================================
-- DLB uses: display_name, rank, first_name, last_name, is_active, join_date
-- PP uses: email, phone, role, operational_role, is_admin, access_token, pin_hash, etc.
-- display_name = "Rank FirstName LastName" (e.g., "CFO John Smith")

CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,

    -- Name fields (used by both apps)
    display_name TEXT NOT NULL,                    -- "Rank FirstName LastName" for display
    rank TEXT DEFAULT '',                          -- CFO, DCFO, SSO, SO, SFF, QFF, FF, RFF, RCFF
    first_name TEXT DEFAULT '',
    last_name TEXT DEFAULT '',

    -- Contact (PP primarily)
    email TEXT DEFAULT '',
    phone TEXT DEFAULT '',

    -- Status (shared)
    is_active INTEGER DEFAULT 1,                  -- DLB: active member, PP: account active
    status TEXT NOT NULL DEFAULT 'active',         -- active, inactive (PP uses this too)

    -- PP roles & auth
    role TEXT DEFAULT 'firefighter',               -- firefighter, officer, admin, superadmin
    operational_role TEXT DEFAULT 'firefighter',    -- firefighter, officer (leave workflow)
    is_admin INTEGER DEFAULT 0,                    -- PP admin panel access

    -- PP authentication
    access_token TEXT,                             -- Magic link session token
    access_expires DATETIME,                       -- Token expiry (5 years from activation)
    pin_hash TEXT,                                 -- PP optional 6-digit PIN (bcrypt)
    push_subscription TEXT,                        -- Legacy push subscription JSON

    -- Dates
    rank_date DATE,                                -- Date current rank was achieved
    join_date DATE,                                -- Date joined brigade
    last_login_at DATETIME,

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    UNIQUE(brigade_id, email),
    CHECK (status IN ('active', 'inactive'))
);

-- ============================================================================
-- SERVICE PERIODS (PP - honors calculation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS service_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,                                -- NULL if currently serving
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- TRUCKS (DLB - vehicle configuration)
-- ============================================================================

CREATE TABLE IF NOT EXISTS trucks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_station INTEGER DEFAULT 0,                 -- Station = catch-all for standby
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
);

-- ============================================================================
-- POSITIONS (DLB - truck crew positions)
-- ============================================================================

CREATE TABLE IF NOT EXISTS positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    truck_id INTEGER NOT NULL,
    name TEXT NOT NULL,                            -- OIC, DR, 1, 2, 3, 4, Standby
    allow_multiple INTEGER DEFAULT 0,              -- 1 for Standby positions
    sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE
);

-- ============================================================================
-- CALLOUTS (DLB - incidents and musters, also used by PP for training musters)
-- ============================================================================

CREATE TABLE IF NOT EXISTS callouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    icad_number TEXT NOT NULL,                     -- FENZ incident number or generated ID for musters
    status TEXT DEFAULT 'active',                  -- active, submitted, locked
    call_date DATE,
    call_time TIME,
    location TEXT,
    duration TEXT,
    call_type TEXT,                                -- Structure Fire, Medical, Training, etc.

    -- Visibility (PP uses this for future training musters)
    visible INTEGER DEFAULT 1,                     -- 0 = hidden future muster

    -- FENZ integration
    fenz_fetched_at DATETIME,

    -- Submission
    submitted_at DATETIME,
    submitted_by TEXT,

    -- SMS upload tracking
    sms_uploaded INTEGER DEFAULT 1,
    sms_uploaded_at DATETIME,
    sms_uploaded_by TEXT,

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
);

-- ============================================================================
-- ATTENDANCE (DLB - the single source of truth for all attendance)
-- ============================================================================
-- Both apps read/write this table:
-- - DLB: manual attendance entry (tap member → position)
-- - PP: leave approval writes status='L', source='portal'
-- - Import: historical data with source='import'

CREATE TABLE IF NOT EXISTS attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    callout_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    truck_id INTEGER NOT NULL,
    position_id INTEGER NOT NULL,
    status CHAR(1) DEFAULT 'I',                   -- I=In attendance, L=Leave, A=Absent
    source TEXT DEFAULT 'manual',                  -- manual, portal, api, import
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (callout_id) REFERENCES callouts(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    UNIQUE(callout_id, member_id)
);

-- ============================================================================
-- CALENDAR EVENTS (PP - calendar/scheduling)
-- ============================================================================

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    location TEXT,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    all_day INTEGER DEFAULT 0,
    recurrence_rule TEXT,                          -- RRULE format for recurring events
    is_training INTEGER DEFAULT 0,                 -- True for training nights
    event_type TEXT DEFAULT 'other',               -- training, meeting, social, firewise, other
    is_visible INTEGER DEFAULT 1,
    adjust_for_holidays INTEGER DEFAULT 0,         -- Auto-shift for Auckland public holidays
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- EVENT EXCEPTIONS (PP - recurring event modifications)
-- ============================================================================

CREATE TABLE IF NOT EXISTS event_exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    exception_date DATE NOT NULL,
    is_cancelled INTEGER DEFAULT 1,
    replacement_date DATE,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE(event_id, exception_date)
);

-- ============================================================================
-- NOTICES (PP - notice board)
-- ============================================================================

CREATE TABLE IF NOT EXISTS notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT,
    type TEXT NOT NULL DEFAULT 'standard',         -- standard, sticky, timed, urgent
    display_from DATETIME,
    display_to DATETIME,
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES members(id) ON DELETE SET NULL,
    CHECK (type IN ('standard', 'sticky', 'timed', 'urgent'))
);

-- ============================================================================
-- LEAVE REQUESTS (PP - member leave management)
-- ============================================================================
-- When approved, a corresponding attendance record is created with status='L'

CREATE TABLE IF NOT EXISTS leave_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    training_date DATE NOT NULL,
    reason TEXT,
    status TEXT NOT NULL DEFAULT 'pending',        -- pending, approved, denied
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    decided_by INTEGER,
    decided_at DATETIME,
    callout_id INTEGER,                            -- FK to callout/muster for this training date
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES members(id) ON DELETE SET NULL,
    FOREIGN KEY (callout_id) REFERENCES callouts(id) ON DELETE SET NULL,
    UNIQUE(member_id, training_date),
    CHECK (status IN ('pending', 'approved', 'denied'))
);

-- ============================================================================
-- EXTENDED LEAVE REQUESTS (PP - long-term leave)
-- ============================================================================

CREATE TABLE IF NOT EXISTS extended_leave_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    trainings_affected INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    decided_by INTEGER,
    decided_at DATETIME,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES members(id) ON DELETE SET NULL,
    CHECK (status IN ('pending', 'approved', 'denied')),
    CHECK (end_date >= start_date)
);

-- ============================================================================
-- AUDIT LOG (unified - both apps write here)
-- ============================================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER,
    member_id INTEGER,                             -- NULL for unauthenticated or system actions
    callout_id INTEGER,                            -- DLB: related callout
    action TEXT NOT NULL,                           -- e.g., 'member.create', 'leave.approve', 'callout.submit'
    entity_type TEXT,                               -- member, event, notice, leave, callout, attendance
    entity_id INTEGER,
    details TEXT DEFAULT '{}',                      -- JSON with change details
    source TEXT DEFAULT 'system',                   -- dlb, portal, system, api
    ip_address TEXT,
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE SET NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- API TOKENS (DLB - external API access)
-- ============================================================================

CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    token_prefix TEXT,                             -- First 16 chars for quick lookup
    name TEXT NOT NULL,                            -- Descriptive name
    permissions TEXT NOT NULL,                      -- JSON array of permission strings
    last_used_at DATETIME,
    expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
);

-- ============================================================================
-- API RATE LIMITS (DLB - per-token throttling)
-- ============================================================================

CREATE TABLE IF NOT EXISTS api_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id INTEGER NOT NULL,
    minute_count INTEGER DEFAULT 0,
    minute_reset DATETIME,
    hour_count INTEGER DEFAULT 0,
    hour_reset DATETIME,
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
);

-- ============================================================================
-- PUSH SUBSCRIPTIONS (PP - Web Push endpoints)
-- ============================================================================

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh_key TEXT NOT NULL,
    auth_key TEXT NOT NULL,
    user_agent TEXT,
    last_used_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE(endpoint)
);

-- ============================================================================
-- PUBLIC HOLIDAYS CACHE (PP - NZ holidays for training shifts)
-- ============================================================================

CREATE TABLE IF NOT EXISTS public_holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    name TEXT NOT NULL,
    region TEXT DEFAULT 'auckland',                -- auckland, national
    year INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, region)
);

-- ============================================================================
-- INVITE TOKENS (PP - magic link invitations)
-- ============================================================================

CREATE TABLE IF NOT EXISTS invite_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL DEFAULT 'firefighter',
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- MAGIC LINKS (PP - email login tokens)
-- ============================================================================

CREATE TABLE IF NOT EXISTS magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- SESSIONS (PP - active user sessions)
-- ============================================================================

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    member_id INTEGER NOT NULL,
    data TEXT,
    ip_address TEXT,
    user_agent TEXT,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- REMEMBER TOKENS (PP - persistent login for PWA)
-- ============================================================================

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    device_name TEXT,
    user_agent TEXT,
    last_used_at DATETIME,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- RATE LIMITS (shared - login/PIN rate limiting)
-- ============================================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier TEXT NOT NULL UNIQUE,               -- e.g., 'pin:puke', 'login:user@email.com'
    attempts INTEGER DEFAULT 1,
    first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME
);

-- ============================================================================
-- SETTINGS (PP - brigade key-value config store)
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    key TEXT NOT NULL,
    value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    UNIQUE(brigade_id, key)
);

-- ============================================================================
-- NOTIFICATIONS (PP - in-app notification center)
-- ============================================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    brigade_id INTEGER NOT NULL,
    type TEXT NOT NULL,                            -- system_alert, message, update, reminder
    title TEXT NOT NULL,
    body TEXT,
    link TEXT,
    data TEXT,                                     -- JSON metadata
    read_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
);

-- ============================================================================
-- NOTIFICATION PREFERENCES (PP - per-user opt-in/out)
-- ============================================================================

CREATE TABLE IF NOT EXISTS notification_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL UNIQUE,
    system_alerts INTEGER DEFAULT 1,
    messages INTEGER DEFAULT 1,
    updates INTEGER DEFAULT 1,
    reminders INTEGER DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- POLLS (PP - survey/voting)
-- ============================================================================

CREATE TABLE IF NOT EXISTS polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    type TEXT NOT NULL DEFAULT 'single',           -- single, multi
    status TEXT NOT NULL DEFAULT 'active',          -- active, closed
    closes_at DATETIME,
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL,
    CHECK (type IN ('single', 'multi')),
    CHECK (status IN ('active', 'closed'))
);

CREATE TABLE IF NOT EXISTS poll_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE(poll_id, option_id, member_id)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

-- Members
CREATE INDEX IF NOT EXISTS idx_members_brigade ON members(brigade_id);
CREATE INDEX IF NOT EXISTS idx_members_email ON members(email);
CREATE INDEX IF NOT EXISTS idx_members_status ON members(status);
CREATE INDEX IF NOT EXISTS idx_members_active ON members(is_active);
CREATE INDEX IF NOT EXISTS idx_members_role ON members(role);
CREATE INDEX IF NOT EXISTS idx_members_operational_role ON members(operational_role);
CREATE INDEX IF NOT EXISTS idx_members_display_name ON members(display_name);

-- Trucks & Positions
CREATE INDEX IF NOT EXISTS idx_trucks_brigade ON trucks(brigade_id);
CREATE INDEX IF NOT EXISTS idx_positions_truck ON positions(truck_id);

-- Callouts
CREATE INDEX IF NOT EXISTS idx_callouts_brigade ON callouts(brigade_id);
CREATE INDEX IF NOT EXISTS idx_callouts_status ON callouts(status);
CREATE INDEX IF NOT EXISTS idx_callouts_date ON callouts(call_date);
CREATE INDEX IF NOT EXISTS idx_callouts_icad ON callouts(icad_number);
CREATE INDEX IF NOT EXISTS idx_callouts_visible ON callouts(visible);

-- Attendance
CREATE INDEX IF NOT EXISTS idx_attendance_callout ON attendance(callout_id);
CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance(member_id);
CREATE INDEX IF NOT EXISTS idx_attendance_status ON attendance(status);
CREATE INDEX IF NOT EXISTS idx_attendance_source ON attendance(source);
CREATE INDEX IF NOT EXISTS idx_attendance_member_callout ON attendance(member_id, callout_id);

-- Events
CREATE INDEX IF NOT EXISTS idx_events_brigade ON events(brigade_id);
CREATE INDEX IF NOT EXISTS idx_events_start ON events(start_time);
CREATE INDEX IF NOT EXISTS idx_events_training ON events(is_training);
CREATE INDEX IF NOT EXISTS idx_events_visible ON events(is_visible);

-- Notices
CREATE INDEX IF NOT EXISTS idx_notices_brigade ON notices(brigade_id);
CREATE INDEX IF NOT EXISTS idx_notices_type ON notices(type);
CREATE INDEX IF NOT EXISTS idx_notices_display ON notices(display_from, display_to);

-- Leave requests
CREATE INDEX IF NOT EXISTS idx_leave_member ON leave_requests(member_id);
CREATE INDEX IF NOT EXISTS idx_leave_date ON leave_requests(training_date);
CREATE INDEX IF NOT EXISTS idx_leave_status ON leave_requests(status);
CREATE INDEX IF NOT EXISTS idx_leave_callout ON leave_requests(callout_id);

-- Extended leave
CREATE INDEX IF NOT EXISTS idx_extended_leave_member ON extended_leave_requests(member_id);
CREATE INDEX IF NOT EXISTS idx_extended_leave_dates ON extended_leave_requests(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_extended_leave_status ON extended_leave_requests(status);

-- Audit log
CREATE INDEX IF NOT EXISTS idx_audit_brigade ON audit_log(brigade_id);
CREATE INDEX IF NOT EXISTS idx_audit_member ON audit_log(member_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_source ON audit_log(source);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);

-- API tokens
CREATE INDEX IF NOT EXISTS idx_api_tokens_brigade ON api_tokens(brigade_id);
CREATE INDEX IF NOT EXISTS idx_api_tokens_prefix ON api_tokens(brigade_id, token_prefix);

-- Push subscriptions
CREATE INDEX IF NOT EXISTS idx_push_member ON push_subscriptions(member_id);

-- Public holidays
CREATE INDEX IF NOT EXISTS idx_holidays_date ON public_holidays(date);
CREATE INDEX IF NOT EXISTS idx_holidays_year ON public_holidays(year);

-- Sessions
CREATE INDEX IF NOT EXISTS idx_sessions_member ON sessions(member_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- Remember tokens
CREATE INDEX IF NOT EXISTS idx_remember_member ON remember_tokens(member_id);
CREATE INDEX IF NOT EXISTS idx_remember_expires ON remember_tokens(expires_at);

-- Magic links
CREATE INDEX IF NOT EXISTS idx_magic_links_member ON magic_links(member_id);
CREATE INDEX IF NOT EXISTS idx_magic_links_expires ON magic_links(expires_at);

-- Rate limits
CREATE INDEX IF NOT EXISTS idx_rate_limits_locked ON rate_limits(locked_until);

-- Notifications
CREATE INDEX IF NOT EXISTS idx_notifications_member ON notifications(member_id);
CREATE INDEX IF NOT EXISTS idx_notifications_brigade ON notifications(brigade_id);
CREATE INDEX IF NOT EXISTS idx_notifications_type ON notifications(type);
CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(member_id, read_at);
CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at);

-- Polls
CREATE INDEX IF NOT EXISTS idx_polls_brigade ON polls(brigade_id);
CREATE INDEX IF NOT EXISTS idx_polls_status ON polls(status);
CREATE INDEX IF NOT EXISTS idx_poll_options_poll ON poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_poll ON poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_member ON poll_votes(member_id);

-- Settings
CREATE INDEX IF NOT EXISTS idx_settings_brigade_key ON settings(brigade_id, key);

-- ============================================================================
-- TRIGGERS (auto-update updated_at timestamps)
-- ============================================================================

CREATE TRIGGER IF NOT EXISTS update_brigades_timestamp
AFTER UPDATE ON brigades
BEGIN
    UPDATE brigades SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_members_timestamp
AFTER UPDATE ON members
BEGIN
    UPDATE members SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_callouts_timestamp
AFTER UPDATE ON callouts
BEGIN
    UPDATE callouts SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_attendance_timestamp
AFTER UPDATE ON attendance
BEGIN
    UPDATE attendance SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_events_timestamp
AFTER UPDATE ON events
BEGIN
    UPDATE events SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_notices_timestamp
AFTER UPDATE ON notices
BEGIN
    UPDATE notices SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_settings_timestamp
AFTER UPDATE ON settings
BEGIN
    UPDATE settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_polls_timestamp
AFTER UPDATE ON polls
BEGIN
    UPDATE polls SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS update_notification_preferences_timestamp
AFTER UPDATE ON notification_preferences
BEGIN
    UPDATE notification_preferences SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;
