/**
 * Notification Center
 *
 * Manages the in-app notification center UI including:
 * - Bell icon badge updates
 * - Notification dropdown/panel
 * - Mark as read functionality
 * - Clear all functionality
 * - Pagination (load more)
 *
 * Issue #26
 */
class NotificationCenter {
    constructor(options = {}) {
        this.basePath = options.basePath || '';
        this.pollInterval = options.pollInterval || 60000; // 1 minute default
        this.pageSize = options.pageSize || 50;

        // State
        this.notifications = [];
        this.unreadCount = 0;
        this.offset = 0;
        this.hasMore = false;
        this.isLoading = false;
        this.isOpen = false;
        this.showingPreferences = false;
        this.preferences = null;
        this.preferencesTypes = null;
        this.pollTimer = null;
        this.savedScrollY = 0;

        // Elements (will be set in init)
        this.bellButton = null;
        this.badge = null;
        this.panel = null;
        this.notificationList = null;
        this.loadMoreButton = null;

        this.init();
    }

    init() {
        // Find existing elements
        this.bellButton = document.getElementById('notification-bell');
        this.badge = document.getElementById('notification-badge');
        this.panel = document.getElementById('notification-panel');
        this.notificationList = document.getElementById('notification-list');
        this.loadMoreButton = document.getElementById('notification-load-more');

        if (!this.bellButton) {
            console.warn('NotificationCenter: Bell button not found');
            return;
        }

        this.setupEventListeners();
        this.fetchUnreadCount();
        this.startPolling();
    }

    setupEventListeners() {
        // Toggle panel on bell click
        this.bellButton.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggle();
        });

        // Close panel when clicking outside
        document.addEventListener('click', (e) => {
            if (this.isOpen && this.panel && !this.panel.contains(e.target) && e.target !== this.bellButton) {
                this.close();
            }
        });

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });

        // Load more button
        if (this.loadMoreButton) {
            this.loadMoreButton.addEventListener('click', () => this.loadMore());
        }

        // Handle panel action buttons (using event delegation)
        if (this.panel) {
            this.panel.addEventListener('click', (e) => this.handlePanelClick(e));
        }
    }

    handlePanelClick(e) {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const action = target.dataset.action;
        const notificationId = target.dataset.notificationId;

        switch (action) {
            case 'mark-read':
                this.markAsRead(parseInt(notificationId));
                break;
            case 'mark-all-read':
                this.markAllAsRead();
                break;
            case 'clear-all':
                this.clearAll();
                break;
            case 'delete':
                this.deleteNotification(parseInt(notificationId));
                break;
            case 'show-settings':
                this.showPreferences();
                break;
            case 'hide-settings':
                this.hidePreferences();
                break;
            case 'toggle-pref':
                const prefType = target.dataset.prefType;
                this.togglePreference(prefType, target.checked);
                break;
        }
    }

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        if (!this.panel) return;

        this.isOpen = true;
        this.panel.classList.add('open');
        this.bellButton?.classList.add('active');

        // Lock body scroll on mobile only (matches CSS media query)
        if (window.innerWidth <= 480) {
            document.body.classList.add('notification-panel-open');
            document.documentElement.classList.add('notification-panel-open');

            // Single handler that checks if touch is inside the scrollable list
            const list = this.list;
            this.touchMoveHandler = (e) => {
                // If touch is inside the notification list, allow it
                if (list && list.contains(e.target)) {
                    // Allow the scroll
                    return;
                }
                // Otherwise prevent scrolling
                e.preventDefault();
            };

            document.addEventListener('touchmove', this.touchMoveHandler, { passive: false });
        }

        // Reset and load notifications
        this.notifications = [];
        this.offset = 0;
        this.fetchNotifications();
    }

    close() {
        if (!this.panel) return;

        this.isOpen = false;
        this.panel.classList.remove('open');
        this.bellButton?.classList.remove('active');

        // Remove touch event handlers
        if (this.bodyTouchMoveHandler) {
            document.body.removeEventListener('touchmove', this.bodyTouchMoveHandler);
            this.bodyTouchMoveHandler = null;
        }
        if (this.listTouchMoveHandler && this.list) {
            this.list.removeEventListener('touchmove', this.listTouchMoveHandler);
            this.listTouchMoveHandler = null;
        }

        // Unlock body scroll
        document.body.classList.remove('notification-panel-open');
        document.documentElement.classList.remove('notification-panel-open');
    }

    async fetchUnreadCount() {
        try {
            const response = await fetch(`${this.basePath}/api/notifications/unread-count`, {
                credentials: 'same-origin'
            });

            if (!response.ok) return;

            const data = await response.json();
            this.updateBadge(data.count);
        } catch (error) {
            console.error('Failed to fetch unread count:', error);
        }
    }

    async fetchNotifications(append = false) {
        if (this.isLoading) return;

        this.isLoading = true;
        this.showLoading();

        try {
            const response = await fetch(
                `${this.basePath}/api/notifications?limit=${this.pageSize}&offset=${this.offset}`,
                { credentials: 'same-origin' }
            );

            if (!response.ok) throw new Error('Failed to fetch notifications');

            const data = await response.json();

            if (append) {
                this.notifications = [...this.notifications, ...data.notifications];
            } else {
                this.notifications = data.notifications;
            }

            this.hasMore = data.has_more;
            this.unreadCount = data.unread_count;

            this.updateBadge(this.unreadCount);
            this.renderNotifications();
        } catch (error) {
            console.error('Failed to fetch notifications:', error);
            this.showError('Failed to load notifications');
        } finally {
            this.isLoading = false;
        }
    }

    loadMore() {
        if (this.hasMore && !this.isLoading) {
            this.offset += this.pageSize;
            this.fetchNotifications(true);
        }
    }

    updateBadge(count) {
        this.unreadCount = count;

        if (!this.badge) return;

        if (count > 0) {
            this.badge.textContent = count > 99 ? '99+' : count.toString();
            this.badge.hidden = false;
            this.badge.classList.add('has-notifications');
        } else {
            this.badge.hidden = true;
            this.badge.classList.remove('has-notifications');
        }
    }

    renderNotifications() {
        if (!this.notificationList) return;

        if (this.notifications.length === 0) {
            this.notificationList.innerHTML = `
                <div class="notification-empty">
                    <span class="notification-empty-icon">&#128276;</span>
                    <p>No notifications</p>
                </div>
            `;
            this.hideLoadMore();
            return;
        }

        const html = this.notifications.map(n => this.renderNotification(n)).join('');
        this.notificationList.innerHTML = html;

        // Show/hide load more button
        if (this.hasMore) {
            this.showLoadMore();
        } else {
            this.hideLoadMore();
        }
    }

    renderNotification(notification) {
        const isUnread = !notification.is_read;
        const timeAgo = this.formatTimeAgo(notification.created_at);

        return `
            <div class="notification-item ${isUnread ? 'unread' : ''}"
                 data-id="${notification.id}"
                 style="--notification-color: ${notification.color}">
                <div class="notification-indicator"></div>
                <div class="notification-content">
                    <div class="notification-header">
                        <span class="notification-title">${this.escapeHtml(notification.title)}</span>
                        <span class="notification-time">${timeAgo}</span>
                    </div>
                    ${notification.body ? `<p class="notification-body">${this.escapeHtml(notification.body)}</p>` : ''}
                    <div class="notification-actions">
                        ${notification.link ? `<a href="${notification.link}" class="notification-link" data-action="mark-read" data-notification-id="${notification.id}">View</a>` : ''}
                        ${isUnread ? `<button type="button" class="notification-action" data-action="mark-read" data-notification-id="${notification.id}">Mark read</button>` : ''}
                        <button type="button" class="notification-action notification-action-delete" data-action="delete" data-notification-id="${notification.id}">Remove</button>
                    </div>
                </div>
            </div>
        `;
    }

    async markAsRead(notificationId) {
        try {
            const response = await fetch(`${this.basePath}/api/notifications/${notificationId}/read`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            });

            if (!response.ok) throw new Error('Failed to mark as read');

            const data = await response.json();

            // Update local state
            const notification = this.notifications.find(n => n.id === notificationId);
            if (notification) {
                notification.is_read = true;
                notification.read_at = new Date().toISOString();
            }

            this.updateBadge(data.unread_count);
            this.renderNotifications();
        } catch (error) {
            console.error('Failed to mark notification as read:', error);
        }
    }

    async markAllAsRead() {
        try {
            const response = await fetch(`${this.basePath}/api/notifications/mark-all-read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            });

            if (!response.ok) throw new Error('Failed to mark all as read');

            // Update local state
            this.notifications.forEach(n => {
                n.is_read = true;
                n.read_at = new Date().toISOString();
            });

            this.updateBadge(0);
            this.renderNotifications();
        } catch (error) {
            console.error('Failed to mark all as read:', error);
        }
    }

    async deleteNotification(notificationId) {
        try {
            const response = await fetch(`${this.basePath}/api/notifications/${notificationId}`, {
                method: 'DELETE',
                credentials: 'same-origin'
            });

            if (!response.ok) throw new Error('Failed to delete notification');

            const data = await response.json();

            // Remove from local state
            this.notifications = this.notifications.filter(n => n.id !== notificationId);

            this.updateBadge(data.unread_count);
            this.renderNotifications();
        } catch (error) {
            console.error('Failed to delete notification:', error);
        }
    }

    async clearAll() {
        if (!confirm('Are you sure you want to clear all notifications?')) {
            return;
        }

        try {
            const response = await fetch(`${this.basePath}/api/notifications/clear`, {
                method: 'DELETE',
                credentials: 'same-origin'
            });

            if (!response.ok) throw new Error('Failed to clear notifications');

            // Clear local state
            this.notifications = [];
            this.offset = 0;
            this.hasMore = false;

            this.updateBadge(0);
            this.renderNotifications();
        } catch (error) {
            console.error('Failed to clear notifications:', error);
        }
    }

    showLoading() {
        if (!this.notificationList) return;

        if (this.notifications.length === 0) {
            this.notificationList.innerHTML = `
                <div class="notification-loading">
                    <div class="notification-spinner"></div>
                    <p>Loading notifications...</p>
                </div>
            `;
        }
    }

    showError(message) {
        if (!this.notificationList) return;

        this.notificationList.innerHTML = `
            <div class="notification-error">
                <p>${this.escapeHtml(message)}</p>
                <button type="button" onclick="notificationCenter.fetchNotifications()">Retry</button>
            </div>
        `;
    }

    showLoadMore() {
        if (this.loadMoreButton) {
            this.loadMoreButton.hidden = false;
        }
    }

    hideLoadMore() {
        if (this.loadMoreButton) {
            this.loadMoreButton.hidden = true;
        }
    }

    startPolling() {
        // Clear any existing timer
        this.stopPolling();

        // Poll for unread count periodically
        this.pollTimer = setInterval(() => {
            if (!this.isOpen) {
                this.fetchUnreadCount();
            }
        }, this.pollInterval);
    }

    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    }

    formatTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffSecs = Math.floor(diffMs / 1000);
        const diffMins = Math.floor(diffSecs / 60);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);

        if (diffSecs < 60) return 'just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;

        return date.toLocaleDateString();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Preferences methods
    async showPreferences() {
        this.showingPreferences = true;

        if (!this.preferences) {
            await this.fetchPreferences();
        }

        this.renderPreferences();
    }

    hidePreferences() {
        this.showingPreferences = false;
        this.renderNotifications();
    }

    async fetchPreferences() {
        try {
            const response = await fetch(`${this.basePath}/api/notifications/preferences`, {
                credentials: 'same-origin'
            });

            if (!response.ok) throw new Error('Failed to fetch preferences');

            const data = await response.json();
            this.preferences = data.preferences;
            this.preferencesTypes = data.types;
        } catch (error) {
            console.error('Failed to fetch preferences:', error);
        }
    }

    async togglePreference(prefType, enabled) {
        try {
            const update = { [prefType]: enabled };

            const response = await fetch(`${this.basePath}/api/notifications/preferences`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(update)
            });

            if (!response.ok) throw new Error('Failed to update preferences');

            const data = await response.json();
            this.preferences = data.preferences;
        } catch (error) {
            console.error('Failed to update preference:', error);
            // Revert the checkbox
            const checkbox = this.notificationList?.querySelector(`[data-pref-type="${prefType}"]`);
            if (checkbox) {
                checkbox.checked = !enabled;
            }
        }
    }

    renderPreferences() {
        if (!this.notificationList || !this.preferences) return;

        const types = this.preferencesTypes || {};

        let html = `
            <div class="notification-preferences">
                <div class="preferences-header">
                    <button type="button" class="preferences-back" data-action="hide-settings">
                        &larr; Back
                    </button>
                    <h4>Notification Settings</h4>
                </div>
                <p class="preferences-description">Choose which notifications you want to receive:</p>
                <div class="preferences-list">
        `;

        for (const [key, type] of Object.entries(types)) {
            const isEnabled = this.preferences[key] ?? true;
            html += `
                <label class="preference-item" style="--pref-color: ${type.color}">
                    <div class="preference-info">
                        <span class="preference-indicator"></span>
                        <div class="preference-text">
                            <span class="preference-label">${this.escapeHtml(type.label)}</span>
                            <span class="preference-desc">${this.escapeHtml(type.description)}</span>
                        </div>
                    </div>
                    <input type="checkbox"
                           class="preference-toggle"
                           data-action="toggle-pref"
                           data-pref-type="${key}"
                           ${isEnabled ? 'checked' : ''}>
                </label>
            `;
        }

        html += `
                </div>
            </div>
        `;

        this.notificationList.innerHTML = html;
        this.hideLoadMore();
    }

    // Public method to manually trigger a refresh (e.g., after receiving a push notification)
    refresh() {
        this.fetchUnreadCount();
        if (this.isOpen) {
            this.notifications = [];
            this.offset = 0;
            this.fetchNotifications();
        }
    }
}

// Initialize when DOM is ready
let notificationCenter;
document.addEventListener('DOMContentLoaded', () => {
    // Get base path from meta tag or global variable
    const basePath = document.querySelector('meta[name="base-path"]')?.content ||
                     window.BASE_PATH || '';

    notificationCenter = new NotificationCenter({ basePath });
});
