-- Puke Portal Database Schema
-- SQLite3
-- Timezone: Pacific/Auckland (NZST/NZDT)

-- Enable foreign keys
PRAGMA foreign_keys = ON;

-- ============================================================================
-- BRIGADES
-- ============================================================================

CREATE TABLE IF NOT EXISTS brigades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    logo_url VARCHAR(255),
    primary_color VARCHAR(7) DEFAULT '#D32F2F',
    accent_color VARCHAR(7) DEFAULT '#1976D2',
    timezone VARCHAR(50) DEFAULT 'Pacific/Auckland',
    training_day INTEGER DEFAULT 1,           -- Day of week: 1=Monday, 7=Sunday
    training_time VARCHAR(5) DEFAULT '19:00', -- 24hr format
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default brigade
INSERT OR IGNORE INTO brigades (id, name, slug) VALUES (1, 'Puke Volunteer Fire Brigade', 'puke');

-- ============================================================================
-- MEMBERS
-- ============================================================================

CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    role VARCHAR(20) NOT NULL DEFAULT 'firefighter',  -- firefighter, officer, admin, superadmin
    rank VARCHAR(20),                                  -- CFO, DCFO, SSO, SO, SFF, QFF, FF, RCFF
    rank_date DATE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',     -- active, inactive
    access_token VARCHAR(255),
    access_expires DATETIME,
    pin_hash VARCHAR(255),
    push_subscription TEXT,
    dlb_member_id INTEGER,                             -- Reference to dlb members table
    last_login_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    UNIQUE(brigade_id, email),
    CHECK (role IN ('firefighter', 'officer', 'admin', 'superadmin')),
    CHECK (status IN ('active', 'inactive'))
);

-- ============================================================================
-- SERVICE PERIODS (for honors calculation)
-- ============================================================================

CREATE TABLE IF NOT EXISTS service_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,                              -- NULL if currently serving
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- CALENDAR EVENTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    location VARCHAR(200),
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    all_day BOOLEAN DEFAULT 0,
    recurrence_rule TEXT,                       -- RRULE format for recurring events
    is_training BOOLEAN DEFAULT 0,              -- True for training nights
    is_visible BOOLEAN DEFAULT 1,               -- Can hide future events
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- EVENT EXCEPTIONS (for recurring events)
-- ============================================================================

CREATE TABLE IF NOT EXISTS event_exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    exception_date DATE NOT NULL,
    is_cancelled BOOLEAN DEFAULT 1,             -- If cancelled, event doesn't occur
    replacement_date DATE,                      -- If rescheduled, new date
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE(event_id, exception_date)
);

-- ============================================================================
-- NOTICES
-- ============================================================================

CREATE TABLE IF NOT EXISTS notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    type VARCHAR(20) NOT NULL DEFAULT 'standard',  -- standard, sticky, timed, urgent
    display_from DATETIME,                          -- NULL = immediately
    display_to DATETIME,                            -- NULL = indefinitely
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES members(id) ON DELETE SET NULL,
    CHECK (type IN ('standard', 'sticky', 'timed', 'urgent'))
);

-- ============================================================================
-- LEAVE REQUESTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS leave_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    training_date DATE NOT NULL,
    reason TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, approved, denied
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    decided_by INTEGER,
    decided_at DATETIME,
    synced_to_dlb BOOLEAN DEFAULT 0,
    dlb_muster_id INTEGER,                          -- Reference to dlb muster record
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES members(id) ON DELETE SET NULL,
    UNIQUE(member_id, training_date),
    CHECK (status IN ('pending', 'approved', 'denied'))
);

-- ============================================================================
-- AUDIT LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER,
    member_id INTEGER,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),                        -- member, event, notice, leave, etc.
    entity_id INTEGER,
    details TEXT,                                   -- JSON with change details
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE SET NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- PUSH SUBSCRIPTIONS
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
-- PUBLIC HOLIDAYS CACHE
-- ============================================================================

CREATE TABLE IF NOT EXISTS public_holidays (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL,
    name VARCHAR(100) NOT NULL,
    region VARCHAR(50) DEFAULT 'auckland',          -- auckland, national
    year INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(date, region)
);

-- ============================================================================
-- INVITE TOKENS
-- ============================================================================

CREATE TABLE IF NOT EXISTS invite_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL DEFAULT 'firefighter',
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL
);

-- ============================================================================
-- SESSIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    member_id INTEGER NOT NULL,
    data TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- ============================================================================
-- RATE LIMITING
-- ============================================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key VARCHAR(255) NOT NULL,                      -- e.g., 'login:email@example.com'
    attempts INTEGER DEFAULT 1,
    first_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME,
    UNIQUE(key)
);

-- ============================================================================
-- SETTINGS (key-value store for brigade settings)
-- ============================================================================

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    key VARCHAR(100) NOT NULL,
    value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    UNIQUE(brigade_id, key)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

-- Members
CREATE INDEX IF NOT EXISTS idx_members_brigade ON members(brigade_id);
CREATE INDEX IF NOT EXISTS idx_members_email ON members(email);
CREATE INDEX IF NOT EXISTS idx_members_role ON members(role);
CREATE INDEX IF NOT EXISTS idx_members_status ON members(status);
CREATE INDEX IF NOT EXISTS idx_members_dlb_id ON members(dlb_member_id);

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
CREATE INDEX IF NOT EXISTS idx_leave_synced ON leave_requests(synced_to_dlb);

-- Audit log
CREATE INDEX IF NOT EXISTS idx_audit_brigade ON audit_log(brigade_id);
CREATE INDEX IF NOT EXISTS idx_audit_member ON audit_log(member_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);

-- Public holidays
CREATE INDEX IF NOT EXISTS idx_holidays_date ON public_holidays(date);
CREATE INDEX IF NOT EXISTS idx_holidays_year ON public_holidays(year);

-- Sessions
CREATE INDEX IF NOT EXISTS idx_sessions_member ON sessions(member_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- Rate limits
CREATE INDEX IF NOT EXISTS idx_rate_limits_key ON rate_limits(key);
CREATE INDEX IF NOT EXISTS idx_rate_limits_locked ON rate_limits(locked_until);

-- ============================================================================
-- TRIGGERS
-- ============================================================================

-- Update updated_at timestamp on members
CREATE TRIGGER IF NOT EXISTS update_members_timestamp
AFTER UPDATE ON members
BEGIN
    UPDATE members SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Update updated_at timestamp on events
CREATE TRIGGER IF NOT EXISTS update_events_timestamp
AFTER UPDATE ON events
BEGIN
    UPDATE events SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Update updated_at timestamp on notices
CREATE TRIGGER IF NOT EXISTS update_notices_timestamp
AFTER UPDATE ON notices
BEGIN
    UPDATE notices SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Update updated_at timestamp on brigades
CREATE TRIGGER IF NOT EXISTS update_brigades_timestamp
AFTER UPDATE ON brigades
BEGIN
    UPDATE brigades SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Update updated_at timestamp on settings
CREATE TRIGGER IF NOT EXISTS update_settings_timestamp
AFTER UPDATE ON settings
BEGIN
    UPDATE settings SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- ============================================================================
-- DLB INTEGRATION
-- ============================================================================

-- Sync logs for tracking DLB sync operations
CREATE TABLE IF NOT EXISTS sync_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation VARCHAR(50) NOT NULL,              -- leave, musters, reveal, members
    reference_id INTEGER,                         -- Related record ID (leave_id, muster_id, etc.)
    status VARCHAR(20) NOT NULL,                  -- success, failed, partial, skipped
    details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sync_logs_operation ON sync_logs(operation);
CREATE INDEX IF NOT EXISTS idx_sync_logs_status ON sync_logs(status);
CREATE INDEX IF NOT EXISTS idx_sync_logs_created ON sync_logs(created_at);

-- Magic links for email authentication
CREATE TABLE IF NOT EXISTS magic_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_magic_links_member ON magic_links(member_id);
CREATE INDEX IF NOT EXISTS idx_magic_links_expires ON magic_links(expires_at);

-- ============================================================================
-- POLLS
-- ============================================================================

CREATE TABLE IF NOT EXISTS polls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brigade_id INTEGER NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    type VARCHAR(20) NOT NULL DEFAULT 'single',   -- 'single' or 'multi'
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active', 'closed'
    closes_at DATETIME,                            -- UTC datetime, null = no expiry
    created_by INTEGER NOT NULL,
    created_at DATETIME NOT NULL,                  -- UTC
    updated_at DATETIME,                           -- UTC
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES members(id) ON DELETE SET NULL,
    CHECK (type IN ('single', 'multi')),
    CHECK (status IN ('active', 'closed'))
);

CREATE TABLE IF NOT EXISTS poll_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    text VARCHAR(200) NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    poll_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    voted_at DATETIME NOT NULL,                    -- UTC
    FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE(poll_id, option_id, member_id)          -- One vote per option per member
);

-- Polls indexes
CREATE INDEX IF NOT EXISTS idx_polls_brigade ON polls(brigade_id);
CREATE INDEX IF NOT EXISTS idx_polls_status ON polls(status);
CREATE INDEX IF NOT EXISTS idx_polls_closes ON polls(closes_at);
CREATE INDEX IF NOT EXISTS idx_poll_options_poll ON poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_poll ON poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_member ON poll_votes(member_id);

-- Update updated_at timestamp on polls
CREATE TRIGGER IF NOT EXISTS update_polls_timestamp
AFTER UPDATE ON polls
BEGIN
    UPDATE polls SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- ============================================================================
-- REMEMBER TOKENS (for persistent "remember me" authentication)
-- ============================================================================

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(100),
    user_agent TEXT,
    last_used_at DATETIME,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_member ON remember_tokens(member_id);
CREATE INDEX IF NOT EXISTS idx_remember_tokens_expires ON remember_tokens(expires_at);
