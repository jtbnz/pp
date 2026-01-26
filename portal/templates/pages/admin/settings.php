<?php
declare(strict_types=1);

/**
 * Admin Settings Template
 *
 * Brigade settings management page.
 */

global $config;

$pageTitle = $pageTitle ?? 'Brigade Settings';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-settings">
    <header class="page-header mb-4">
        <h1>Brigade Settings</h1>
        <p class="text-secondary">Configure <?= e($brigade['name'] ?? 'brigade') ?> settings</p>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <form action="<?= url('/admin/settings') ?>" method="POST" class="settings-form">
        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_method" value="PUT">

        <!-- Training Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">Training Settings</h2>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="training_day" class="form-label">Training Day</label>
                        <select id="training_day" name="training_day" class="form-select">
                            <?php
                            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                            foreach ($days as $day):
                            ?>
                            <option value="<?= $day ?>" <?= ($settings['training.day'] ?? 'monday') === $day ? 'selected' : '' ?>>
                                <?= ucfirst($day) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="training_time" class="form-label">Training Time</label>
                        <input type="time" id="training_time" name="training_time" class="form-input"
                               value="<?= e($settings['training.time'] ?? '19:00') ?>">
                    </div>

                    <div class="form-group">
                        <label for="training_duration" class="form-label">Duration (hours)</label>
                        <select id="training_duration" name="training_duration" class="form-select">
                            <?php for ($h = 1; $h <= 4; $h++): ?>
                            <option value="<?= $h ?>" <?= (int)($settings['training.duration_hours'] ?? 2) === $h ? 'selected' : '' ?>>
                                <?= $h ?> hour<?= $h > 1 ? 's' : '' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="training_location" class="form-label">Default Location</label>
                    <input type="text" id="training_location" name="training_location" class="form-input"
                           value="<?= e($settings['training.location'] ?? 'Fire Station') ?>"
                           placeholder="e.g., Fire Station">
                </div>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="training_move_on_holiday" value="1"
                               <?= !empty($settings['training.move_on_holiday']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">Move training to Tuesday when Monday is a public holiday</span>
                    </label>
                    <span class="form-hint">Auckland Anniversary, Waitangi Day, and other Auckland public holidays</span>
                </div>
            </div>
        </section>

        <!-- Notification Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">Notification Settings</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-3">Configure when push notifications are sent to members.</p>

                <div class="checkbox-group">
                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="notify_leave_request" value="1"
                                   <?= !empty($settings['notifications.leave_request']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">New leave request submitted</span>
                        </label>
                        <span class="form-hint">Sent to officers and admins</span>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="notify_leave_approved" value="1"
                                   <?= !empty($settings['notifications.leave_approved']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">Leave request approved</span>
                        </label>
                        <span class="form-hint">Sent to the requesting member</span>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="notify_new_notice" value="1"
                                   <?= !empty($settings['notifications.new_notice']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">New notice posted</span>
                        </label>
                        <span class="form-hint">Sent to all active members</span>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="notify_urgent_notice" value="1"
                                   <?= !empty($settings['notifications.urgent_notice']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">Urgent notice posted</span>
                        </label>
                        <span class="form-hint">High-priority notification to all members</span>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="notify_training_reminder" value="1"
                                   <?= !empty($settings['notifications.training_reminder']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">Training reminder</span>
                        </label>
                    </div>
                </div>

                <div class="form-group mt-2">
                    <label for="training_reminder_hours" class="form-label">Send training reminder</label>
                    <select id="training_reminder_hours" name="training_reminder_hours" class="form-select" style="width: auto;">
                        <?php
                        $hours = [2, 4, 6, 12, 24, 48];
                        foreach ($hours as $h):
                        ?>
                        <option value="<?= $h ?>" <?= (int)($settings['notifications.training_reminder_hours'] ?? 24) === $h ? 'selected' : '' ?>>
                            <?= $h ?> hours before
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <!-- Leave Request Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">Leave Request Settings</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="max_advance_trainings" class="form-label">Maximum trainings in advance</label>
                    <select id="max_advance_trainings" name="max_advance_trainings" class="form-select" style="width: auto;">
                        <?php for ($n = 1; $n <= 6; $n++): ?>
                        <option value="<?= $n ?>" <?= (int)($settings['leave.max_advance_trainings'] ?? 3) === $n ? 'selected' : '' ?>>
                            <?= $n ?> training<?= $n > 1 ? 's' : '' ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                    <span class="form-hint">How far in advance members can request leave</span>
                </div>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="leave_require_approval" value="1"
                               <?= !empty($settings['leave.require_approval']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">Require approval for leave requests</span>
                    </label>
                    <span class="form-hint">If unchecked, leave is automatically approved</span>
                </div>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="leave_auto_approve_officers" value="1"
                               <?= !empty($settings['leave.auto_approve_officers']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">Auto-approve officer leave requests</span>
                    </label>
                    <span class="form-hint">Officers' leave requests are automatically approved</span>
                </div>
            </div>
        </section>

        <!-- Display Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">Display Settings</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="display_show_ranks" value="1"
                               <?= !empty($settings['display.show_ranks']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">Show member ranks</span>
                    </label>
                    <span class="form-hint">Display FF, QFF, SFF etc. next to member names</span>
                </div>

                <div class="form-group">
                    <label for="calendar_start_day" class="form-label">Calendar week starts on</label>
                    <select id="calendar_start_day" name="calendar_start_day" class="form-select" style="width: auto;">
                        <option value="0" <?= (int)($settings['display.calendar_start_day'] ?? 0) === 0 ? 'selected' : '' ?>>Sunday</option>
                        <option value="1" <?= (int)($settings['display.calendar_start_day'] ?? 0) === 1 ? 'selected' : '' ?>>Monday</option>
                    </select>
                </div>
            </div>
        </section>

        <!-- Calendar Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">Calendar Settings</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="calendar_show_holidays" value="1"
                               <?= ($settings['calendar.show_holidays'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <span class="checkbox-label">Show public holidays on calendar</span>
                    </label>
                    <span class="form-hint">Display NZ public holidays as small dots on calendar dates</span>
                </div>

                <div class="form-group">
                    <label for="calendar_holiday_region" class="form-label">Holiday Region</label>
                    <select id="calendar_holiday_region" name="calendar_holiday_region" class="form-select" style="width: auto;">
                        <?php
                        require_once __DIR__ . '/../../../src/Services/HolidayService.php';
                        $regions = HolidayService::getSupportedRegions();
                        $selectedRegion = $settings['calendar.holiday_region'] ?? 'auckland';
                        foreach ($regions as $code => $name):
                        ?>
                        <option value="<?= e($code) ?>" <?= $selectedRegion === $code ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint">Select your region for regional anniversary days</span>
                </div>
            </div>
        </section>

        <!-- DLB Integration Settings -->
        <section class="settings-section card mb-3">
            <div class="card-header">
                <h2 class="card-title">DLB Integration</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-3">Configure synchronization with the DLB attendance system.</p>

                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" name="dlb_enabled" value="1" id="dlb_enabled"
                               <?= !empty($settings['dlb.enabled']) ? 'checked' : '' ?>>
                        <span class="checkbox-label">Enable DLB integration</span>
                    </label>
                    <span class="form-hint">Sync leave requests and muster data with DLB</span>
                </div>

                <div class="dlb-settings" id="dlb-settings">
                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="dlb_auto_sync" value="1"
                                   <?= !empty($settings['dlb.auto_sync']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">Auto-sync approved leave</span>
                        </label>
                        <span class="form-hint">Automatically sync leave to DLB when approved</span>
                    </div>

                    <div class="dlb-status mt-3">
                        <h3 class="text-sm font-semibold mb-2">Connection Status</h3>
                        <div id="dlb-connection-status" class="connection-status">
                            <span class="status-indicator status-checking"></span>
                            <span class="status-text">Checking connection...</span>
                        </div>
                        <button type="button" id="test-dlb-connection" class="btn btn-secondary btn-sm mt-2">
                            Test Connection
                        </button>
                    </div>

                    <div class="dlb-sync mt-3">
                        <h3 class="text-sm font-semibold mb-2">Member Sync</h3>
                        <p class="text-secondary text-sm mb-2">Link Portal members to their DLB records (required for attendance).</p>
                        <div id="dlb-member-sync-status" class="connection-status mb-2" style="display: none;">
                            <span class="status-indicator"></span>
                            <span class="status-text"></span>
                        </div>
                        <button type="button" id="sync-members" class="btn btn-secondary btn-sm">
                            Sync Members from DLB
                        </button>
                    </div>

                    <div class="dlb-sync mt-3">
                        <h3 class="text-sm font-semibold mb-2">Attendance Sync</h3>
                        <p class="text-secondary text-sm mb-2">Pull attendance history from DLB to display on member profiles.</p>
                        <div id="dlb-sync-status" class="connection-status mb-2" style="display: none;">
                            <span class="status-indicator"></span>
                            <span class="status-text"></span>
                        </div>
                        <button type="button" id="sync-attendance" class="btn btn-secondary btn-sm">
                            Sync Attendance Now
                        </button>
                        <button type="button" id="sync-attendance-full" class="btn btn-secondary btn-sm">
                            Full Sync (12 months)
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <a href="<?= url('/admin') ?>" class="btn">Cancel</a>
        </div>
    </form>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.settings-section .card-header {
    background: var(--bg-secondary, #f5f5f5);
    padding: 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.settings-section .card-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.settings-section .card-body {
    padding: 1.5rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.form-checkbox input[type="checkbox"] {
    width: 1.25rem;
    height: 1.25rem;
    cursor: pointer;
}

.checkbox-label {
    font-weight: 500;
}

.checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.dlb-settings {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border, #e0e0e0);
}

.dlb-settings.hidden {
    display: none;
}

.connection-status {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 4px;
}

.status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.status-checking {
    background: #FFC107;
}

.status-connected {
    background: #4CAF50;
}

.status-error {
    background: var(--error, #D32F2F);
}

.status-disabled {
    background: #9E9E9E;
}

.text-sm {
    font-size: 0.875rem;
}

.font-semibold {
    font-weight: 600;
}
</style>

<script>
// Dismiss flash messages
document.querySelectorAll('.flash-dismiss').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.flash-message').remove();
    });
});

// Toggle DLB settings visibility
const dlbEnabledCheckbox = document.getElementById('dlb_enabled');
const dlbSettings = document.getElementById('dlb-settings');

// Test DLB connection - define these BEFORE toggleDlbSettings since it uses checkDlbConnection
const testConnectionBtn = document.getElementById('test-dlb-connection');
const connectionStatus = document.getElementById('dlb-connection-status');

async function checkDlbConnection() {
    const indicator = connectionStatus.querySelector('.status-indicator');
    const text = connectionStatus.querySelector('.status-text');

    indicator.className = 'status-indicator status-checking';
    text.textContent = 'Checking connection...';

    try {
        const response = await fetch('<?= url('/api/sync/test-connection') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        const data = await response.json();

        if (data.success && data.connected) {
            indicator.className = 'status-indicator status-connected';
            text.textContent = 'Connected to DLB';
        } else {
            indicator.className = 'status-indicator status-error';
            text.textContent = data.error || 'Connection failed';
        }
    } catch (error) {
        indicator.className = 'status-indicator status-error';
        text.textContent = 'Connection error: ' + error.message;
    }
}

testConnectionBtn.addEventListener('click', checkDlbConnection);

function toggleDlbSettings() {
    if (dlbEnabledCheckbox.checked) {
        dlbSettings.classList.remove('hidden');
        checkDlbConnection();
    } else {
        dlbSettings.classList.add('hidden');
    }
}

dlbEnabledCheckbox.addEventListener('change', toggleDlbSettings);
toggleDlbSettings();

// Sync attendance buttons
const syncBtn = document.getElementById('sync-attendance');
const syncFullBtn = document.getElementById('sync-attendance-full');
const syncStatus = document.getElementById('dlb-sync-status');

async function syncAttendance(fullSync = false) {
    const indicator = syncStatus.querySelector('.status-indicator');
    const text = syncStatus.querySelector('.status-text');

    syncStatus.style.display = 'flex';
    indicator.className = 'status-indicator status-checking';
    text.textContent = fullSync ? 'Syncing 12 months of attendance...' : 'Syncing recent attendance...';

    syncBtn.disabled = true;
    syncFullBtn.disabled = true;

    try {
        const response = await fetch('<?= url('/api/attendance/sync') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ full_sync: fullSync })
        });

        const data = await response.json();

        if (data.success) {
            indicator.className = 'status-indicator status-connected';
            text.textContent = `Sync complete: ${data.created || 0} new, ${data.updated || 0} updated`;
        } else {
            indicator.className = 'status-indicator status-error';
            text.textContent = data.error || 'Sync failed';
        }
    } catch (error) {
        indicator.className = 'status-indicator status-error';
        text.textContent = 'Sync error: ' + error.message;
    } finally {
        syncBtn.disabled = false;
        syncFullBtn.disabled = false;
    }
}

syncBtn.addEventListener('click', () => syncAttendance(false));
syncFullBtn.addEventListener('click', () => syncAttendance(true));

// Sync members button
const syncMembersBtn = document.getElementById('sync-members');
const memberSyncStatus = document.getElementById('dlb-member-sync-status');

async function syncMembers() {
    const indicator = memberSyncStatus.querySelector('.status-indicator');
    const text = memberSyncStatus.querySelector('.status-text');

    memberSyncStatus.style.display = 'flex';
    indicator.className = 'status-indicator status-checking';
    text.textContent = 'Syncing members from DLB...';

    syncMembersBtn.disabled = true;

    try {
        const response = await fetch('<?= url('/api/sync/import-members') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            indicator.className = 'status-indicator status-connected';
            text.textContent = data.message || 'Members synced successfully';
        } else {
            indicator.className = 'status-indicator status-error';
            text.textContent = data.error || 'Sync failed';
        }
    } catch (error) {
        indicator.className = 'status-indicator status-error';
        text.textContent = 'Sync error: ' + error.message;
    } finally {
        syncMembersBtn.disabled = false;
    }
}

syncMembersBtn.addEventListener('click', syncMembers);
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
?>
