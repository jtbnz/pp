-- Migration: Add notifications system
-- Issue #26: Notification Center

-- ============================================================================
-- NOTIFICATIONS
-- ============================================================================

-- User notifications (in-app notification history)
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL,
    brigade_id INTEGER NOT NULL,
    type VARCHAR(50) NOT NULL,              -- 'system_alert', 'message', 'update', 'reminder'
    title VARCHAR(255) NOT NULL,
    body TEXT,
    link VARCHAR(255),                       -- URL to navigate to when clicked
    data TEXT,                               -- JSON for additional metadata
    read_at DATETIME,                        -- NULL = unread
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
);

-- Indexes for notifications
CREATE INDEX IF NOT EXISTS idx_notifications_member ON notifications(member_id);
CREATE INDEX IF NOT EXISTS idx_notifications_brigade ON notifications(brigade_id);
CREATE INDEX IF NOT EXISTS idx_notifications_type ON notifications(type);
CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications(member_id, read_at);
CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at);

-- ============================================================================
-- NOTIFICATION PREFERENCES
-- ============================================================================

-- User notification preferences (opt-in/out per notification type)
CREATE TABLE IF NOT EXISTS notification_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    member_id INTEGER NOT NULL UNIQUE,
    system_alerts BOOLEAN DEFAULT 1,         -- Red - urgent/system alerts
    messages BOOLEAN DEFAULT 1,              -- Blue - general messages
    updates BOOLEAN DEFAULT 1,               -- Green - status updates
    reminders BOOLEAN DEFAULT 1,             -- Yellow - training/event reminders
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- Trigger to update updated_at on notification_preferences
CREATE TRIGGER IF NOT EXISTS update_notification_preferences_timestamp
AFTER UPDATE ON notification_preferences
BEGIN
    UPDATE notification_preferences SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;
