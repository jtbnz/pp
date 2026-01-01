/**
 * Puke Portal - Main JavaScript
 *
 * Application initialization, navigation, and core utilities.
 * ES6+ with no dependencies.
 */

'use strict';

// ============================================================================
// App Namespace
// ============================================================================

const App = {
    version: '1.0.0',
    debug: false,

    // DOM elements cache
    elements: {},

    // State
    state: {
        online: navigator.onLine,
        sidebarOpen: false,
        userMenuOpen: false,
        swRegistration: null,
        swUpdateAvailable: false,
    },

    /**
     * Initialize the application
     */
    init() {
        // Cache DOM elements
        this.cacheElements();

        // Set up event listeners
        this.bindEvents();

        // Initialize components
        this.initServiceWorker();
        this.initOfflineDetection();
        this.initNavigation();
        this.initForms();
        this.initToasts();

        // Log initialization
        this.log('App initialized');
    },

    // ========================================================================
    // Service Worker
    // ========================================================================

    /**
     * Register and manage service worker
     */
    async initServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            this.log('Service Worker not supported');
            return;
        }

        try {
            // Register service worker with correct base path
            const basePath = window.BASE_PATH || '';
            const swPath = basePath + '/sw.js';
            const swScope = basePath + '/';

            const registration = await navigator.serviceWorker.register(swPath, {
                scope: swScope
            });

            this.state.swRegistration = registration;
            this.log('Service Worker registered:', registration.scope);

            // Handle updates
            registration.addEventListener('updatefound', () => {
                this.handleSwUpdate(registration);
            });

            // Check for updates periodically (every hour)
            setInterval(() => {
                registration.update().catch(() => {});
            }, 60 * 60 * 1000);

            // Listen for messages from service worker
            navigator.serviceWorker.addEventListener('message', (event) => {
                this.handleSwMessage(event);
            });

            // Handle controller change (new SW activated)
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                this.log('New Service Worker activated');
                // Optionally reload to ensure fresh content
                // window.location.reload();
            });

        } catch (error) {
            console.error('Service Worker registration failed:', error);
        }
    },

    /**
     * Handle service worker update
     */
    handleSwUpdate(registration) {
        const newWorker = registration.installing;

        if (!newWorker) return;

        newWorker.addEventListener('statechange', () => {
            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                // New update available
                this.state.swUpdateAvailable = true;
                this.log('New version available');
                this.showUpdateNotification();
            }
        });
    },

    /**
     * Show update available notification
     */
    showUpdateNotification() {
        const toast = this.showToast(
            'A new version is available. Tap to update.',
            'info',
            0 // Persistent
        );

        if (toast) {
            toast.style.cursor = 'pointer';
            toast.addEventListener('click', () => {
                this.applySwUpdate();
            });
        }
    },

    /**
     * Apply service worker update
     */
    applySwUpdate() {
        if (this.state.swRegistration && this.state.swRegistration.waiting) {
            // Tell waiting SW to skip waiting and take over
            this.state.swRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
        }

        // Reload to get new version
        window.location.reload();
    },

    /**
     * Handle messages from service worker
     */
    handleSwMessage(event) {
        const { type, data } = event.data || {};

        switch (type) {
            case 'SYNC_COMPLETE':
                this.log('Background sync completed');
                this.showToast('Your changes have been synced.', 'success');
                // Dispatch event for other components
                window.dispatchEvent(new CustomEvent('syncComplete', { detail: data }));
                break;

            case 'CACHE_UPDATED':
                this.log('Cache updated');
                break;

            default:
                this.log('SW message:', event.data);
        }
    },

    /**
     * Send message to service worker
     */
    async sendSwMessage(message) {
        if (!this.state.swRegistration || !navigator.serviceWorker.controller) {
            return null;
        }

        return new Promise((resolve) => {
            const messageChannel = new MessageChannel();
            messageChannel.port1.onmessage = (event) => {
                resolve(event.data);
            };

            navigator.serviceWorker.controller.postMessage(message, [messageChannel.port2]);
        });
    },

    /**
     * Clear all service worker caches
     */
    async clearSwCache() {
        const result = await this.sendSwMessage({ type: 'CLEAR_CACHE' });
        if (result?.success) {
            this.showToast('Cache cleared successfully', 'success');
        }
        return result;
    },

    /**
     * Cache frequently accessed DOM elements
     */
    cacheElements() {
        this.elements = {
            offlineIndicator: document.getElementById('offline-indicator'),
            toastContainer: document.getElementById('toast-container'),
            menuToggle: document.querySelector('.menu-toggle'),
            sidebar: document.querySelector('.sidebar'),
            sidebarOverlay: document.querySelector('.sidebar-overlay'),
            userToggle: document.querySelector('.user-toggle'),
            userDropdown: document.querySelector('.user-dropdown'),
            flashMessages: document.querySelectorAll('.flash-message'),
        };
    },

    /**
     * Bind global event listeners
     */
    bindEvents() {
        // Global click handler for closing menus
        document.addEventListener('click', (e) => this.handleGlobalClick(e));

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeAllMenus();
            }
        });

        // Flash message dismiss buttons
        this.elements.flashMessages.forEach((flash) => {
            const dismissBtn = flash.querySelector('.flash-dismiss');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', () => {
                    flash.remove();
                });
            }
        });
    },

    /**
     * Handle clicks outside of menus to close them
     */
    handleGlobalClick(e) {
        // Close user dropdown if clicking outside
        if (this.state.userMenuOpen && this.elements.userDropdown) {
            if (!e.target.closest('.user-menu')) {
                this.closeUserMenu();
            }
        }
    },

    // ========================================================================
    // Offline Detection
    // ========================================================================

    initOfflineDetection() {
        window.addEventListener('online', () => this.handleOnlineStatus(true));
        window.addEventListener('offline', () => this.handleOnlineStatus(false));

        // Set initial state
        this.handleOnlineStatus(navigator.onLine);

        // Update offline indicator with pending count
        this.updatePendingIndicator();
    },

    handleOnlineStatus(isOnline) {
        this.state.online = isOnline;

        if (this.elements.offlineIndicator) {
            this.elements.offlineIndicator.hidden = isOnline;
        }

        // Update body class for CSS-based offline handling
        document.body.classList.toggle('is-offline', !isOnline);

        if (isOnline) {
            this.log('Connection restored');
            // Trigger any pending syncs
            this.syncPendingData();
        } else {
            this.log('Connection lost');
            this.showToast('You are offline. Some features may be unavailable.', 'warning');
        }
    },

    /**
     * Update pending requests indicator
     */
    async updatePendingIndicator() {
        if (typeof offlineStorage === 'undefined') return;

        try {
            const count = await offlineStorage.getPendingCount();
            const indicator = document.getElementById('pending-sync-count');

            if (indicator) {
                indicator.textContent = count;
                indicator.hidden = count === 0;
            }

            // Update offline indicator text if pending
            if (this.elements.offlineIndicator && count > 0 && !this.state.online) {
                const text = this.elements.offlineIndicator.querySelector('span:last-child');
                if (text) {
                    text.textContent = `You're offline (${count} pending)`;
                }
            }
        } catch (error) {
            this.log('Failed to get pending count:', error);
        }
    },

    /**
     * Sync any data that was queued while offline
     */
    async syncPendingData() {
        // First, try background sync if supported
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            try {
                const registration = await navigator.serviceWorker.ready;
                await registration.sync.register('sync-pending-data');
                this.log('Background sync registered');
                return;
            } catch (err) {
                this.log('Background sync not available, trying manual sync:', err);
            }
        }

        // Fallback: manual sync using OfflineStorage
        if (typeof offlineStorage !== 'undefined') {
            try {
                const result = await offlineStorage.processPendingRequests();
                if (result.success > 0) {
                    this.showToast(`Synced ${result.success} pending changes.`, 'success');
                }
                if (result.failed > 0) {
                    this.showToast(`${result.failed} changes failed to sync.`, 'warning');
                }
                this.updatePendingIndicator();
            } catch (error) {
                this.log('Manual sync failed:', error);
            }
        }
    },

    /**
     * Queue an action for later sync when offline
     * @param {string} url - API endpoint
     * @param {string} method - HTTP method
     * @param {Object} body - Request body
     * @returns {Promise<boolean>} - True if queued successfully
     */
    async queueOfflineAction(url, method, body = null) {
        if (typeof offlineStorage === 'undefined') {
            console.error('OfflineStorage not available');
            return false;
        }

        try {
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            };

            // Add CSRF token if available
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                             document.querySelector('input[name="_csrf_token"]')?.value;
            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
            }

            await offlineStorage.queueRequest(url, method, headers, body);
            this.updatePendingIndicator();
            this.showToast('Action queued. Will sync when online.', 'info');
            return true;
        } catch (error) {
            console.error('Failed to queue action:', error);
            return false;
        }
    },

    // ========================================================================
    // Navigation
    // ========================================================================

    initNavigation() {
        // Menu toggle (mobile sidebar)
        if (this.elements.menuToggle) {
            this.elements.menuToggle.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }

        // Sidebar overlay click
        if (this.elements.sidebarOverlay) {
            this.elements.sidebarOverlay.addEventListener('click', () => {
                this.closeSidebar();
            });
        }

        // User menu toggle
        if (this.elements.userToggle) {
            this.elements.userToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleUserMenu();
            });
        }
    },

    toggleSidebar() {
        this.state.sidebarOpen = !this.state.sidebarOpen;

        if (this.elements.sidebar) {
            this.elements.sidebar.classList.toggle('open', this.state.sidebarOpen);
        }

        if (this.elements.sidebarOverlay) {
            this.elements.sidebarOverlay.hidden = !this.state.sidebarOpen;
            this.elements.sidebarOverlay.classList.toggle('visible', this.state.sidebarOpen);
        }

        if (this.elements.menuToggle) {
            this.elements.menuToggle.setAttribute('aria-expanded', this.state.sidebarOpen);
        }

        // Prevent body scroll when sidebar is open
        document.body.style.overflow = this.state.sidebarOpen ? 'hidden' : '';
    },

    closeSidebar() {
        if (this.state.sidebarOpen) {
            this.toggleSidebar();
        }
    },

    toggleUserMenu() {
        this.state.userMenuOpen = !this.state.userMenuOpen;

        if (this.elements.userDropdown) {
            this.elements.userDropdown.hidden = !this.state.userMenuOpen;
        }

        if (this.elements.userToggle) {
            this.elements.userToggle.setAttribute('aria-expanded', this.state.userMenuOpen);
        }
    },

    closeUserMenu() {
        if (this.state.userMenuOpen) {
            this.state.userMenuOpen = false;
            if (this.elements.userDropdown) {
                this.elements.userDropdown.hidden = true;
            }
            if (this.elements.userToggle) {
                this.elements.userToggle.setAttribute('aria-expanded', 'false');
            }
        }
    },

    closeAllMenus() {
        this.closeSidebar();
        this.closeUserMenu();
    },

    // ========================================================================
    // Forms
    // ========================================================================

    initForms() {
        // Add loading state to forms on submit
        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', (e) => {
                const submitBtn = form.querySelector('[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('loading');

                    // Store original text
                    submitBtn.dataset.originalText = submitBtn.textContent;
                    submitBtn.textContent = 'Loading...';
                }
            });
        });
    },

    /**
     * Reset form button to original state
     */
    resetFormButton(form) {
        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
            if (submitBtn.dataset.originalText) {
                submitBtn.textContent = submitBtn.dataset.originalText;
            }
        }
    },

    // ========================================================================
    // Toast Notifications
    // ========================================================================

    initToasts() {
        // Ensure container exists
        if (!this.elements.toastContainer) {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
            this.elements.toastContainer = container;
        }
    },

    /**
     * Show a toast notification
     * @param {string} message - Toast message
     * @param {string} type - Toast type: info, success, warning, error
     * @param {number} duration - Duration in ms (0 = persistent)
     */
    showToast(message, type = 'info', duration = 5000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const icons = {
            info: '&#8505;',     // Information
            success: '&#10003;', // Checkmark
            warning: '&#9888;',  // Warning
            error: '&#10007;',   // Cross
        };

        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <div class="toast-content">
                <p class="toast-message">${this.escapeHtml(message)}</p>
            </div>
            <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
        `;

        // Close button handler
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => {
            this.dismissToast(toast);
        });

        // Add to container
        this.elements.toastContainer.appendChild(toast);

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                this.dismissToast(toast);
            }, duration);
        }

        return toast;
    },

    /**
     * Dismiss a toast notification
     */
    dismissToast(toast) {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    },

    // ========================================================================
    // API Utilities
    // ========================================================================

    /**
     * Make an API request with offline support
     * @param {string} url - API endpoint
     * @param {Object} options - Fetch options
     * @param {Object} offlineOptions - Offline behavior options
     * @returns {Promise<Object>} - JSON response
     */
    async api(url, options = {}, offlineOptions = {}) {
        const {
            queueIfOffline = true,  // Queue non-GET requests if offline
            useCache = false,       // Use cached response for GET if available
            cacheTTL = 5 * 60 * 1000, // Cache TTL (5 minutes default)
        } = offlineOptions;

        const defaults = {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        };

        // Add CSRF token for non-GET requests
        if (options.method && options.method !== 'GET') {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                             document.querySelector('input[name="_csrf_token"]')?.value;
            if (csrfToken) {
                defaults.headers['X-CSRF-Token'] = csrfToken;
            }
        }

        const config = {
            ...defaults,
            ...options,
            headers: {
                ...defaults.headers,
                ...options.headers,
            },
        };

        const isGet = !config.method || config.method === 'GET';
        const cacheKey = `api:${url}`;

        // Convert body to JSON if needed
        let bodyData = null;
        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            bodyData = config.body;
            config.body = JSON.stringify(config.body);
        }

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw new ApiError(data.error || 'Request failed', response.status, data);
            }

            // Cache successful GET responses
            if (isGet && useCache && typeof offlineStorage !== 'undefined') {
                offlineStorage.cacheData(cacheKey, data, cacheTTL).catch(() => {});
            }

            return data;
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }

            // Network error - handle offline
            if (!navigator.onLine) {
                // For GET requests, try cache
                if (isGet && useCache && typeof offlineStorage !== 'undefined') {
                    try {
                        const cached = await offlineStorage.getCached(cacheKey);
                        if (cached) {
                            this.log('Returning cached data for:', url);
                            return { ...cached, _cached: true };
                        }
                    } catch (e) {
                        // Ignore cache errors
                    }
                }

                // For non-GET requests, queue for later
                if (!isGet && queueIfOffline) {
                    const queued = await this.queueOfflineAction(url, config.method, bodyData);
                    if (queued) {
                        return {
                            success: true,
                            queued: true,
                            message: 'Action queued for sync when online'
                        };
                    }
                }

                throw new ApiError('You are offline', 0, null);
            }

            throw new ApiError('Network error', 0, null);
        }
    },

    // ========================================================================
    // Utility Functions
    // ========================================================================

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    /**
     * Format a date for display
     */
    formatDate(date, options = {}) {
        const defaults = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        };

        return new Intl.DateTimeFormat('en-NZ', { ...defaults, ...options }).format(new Date(date));
    },

    /**
     * Format time for display
     */
    formatTime(date) {
        return new Intl.DateTimeFormat('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(date));
    },

    /**
     * Format relative time (e.g., "5 minutes ago")
     */
    formatRelativeTime(date) {
        const now = new Date();
        const then = new Date(date);
        const diff = (now - then) / 1000; // seconds

        if (diff < 60) {
            return 'just now';
        } else if (diff < 3600) {
            const mins = Math.floor(diff / 60);
            return `${mins} minute${mins > 1 ? 's' : ''} ago`;
        } else if (diff < 86400) {
            const hours = Math.floor(diff / 3600);
            return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        } else if (diff < 604800) {
            const days = Math.floor(diff / 86400);
            return `${days} day${days > 1 ? 's' : ''} ago`;
        } else {
            return this.formatDate(date, { weekday: undefined });
        }
    },

    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Throttle function
     */
    throttle(func, limit) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func(...args);
                inThrottle = true;
                setTimeout(() => (inThrottle = false), limit);
            }
        };
    },

    /**
     * Log to console in debug mode
     */
    log(...args) {
        if (this.debug) {
            console.log('[App]', ...args);
        }
    },
};

// ============================================================================
// Custom Error Class for API
// ============================================================================

class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

// ============================================================================
// Initialize on DOM Ready
// ============================================================================

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => App.init());
} else {
    App.init();
}

// Export for use in other modules
window.App = App;
window.ApiError = ApiError;
