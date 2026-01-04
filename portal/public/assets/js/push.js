/**
 * Puke Portal - Push Notifications Manager
 *
 * Handles Web Push subscription and notification management.
 * ES6+ with no dependencies.
 */

'use strict';

// ============================================================================
// Push Notification Handler Class
// ============================================================================

class PushNotificationHandler {
    constructor() {
        this.swRegistration = null;
        this.isSubscribed = false;
        this.publicKey = null;
        this.isInitialized = false;
        this.initError = null;
    }

    /**
     * Get base path for URLs
     */
    get basePath() {
        return window.BASE_PATH || '';
    }

    /**
     * Initialize the push handler
     * @returns {Promise<boolean>} True if push is supported and ready
     */
    async init() {
        console.log('[Push] Starting initialization...');
        console.log('[Push] Browser info:', {
            userAgent: navigator.userAgent,
            serviceWorker: 'serviceWorker' in navigator,
            pushManager: 'PushManager' in window,
            notification: 'Notification' in window,
            notificationPermission: 'Notification' in window ? Notification.permission : 'N/A'
        });

        // Check for required browser features
        if (!('serviceWorker' in navigator)) {
            console.log('[Push] Service workers are not supported');
            this.initError = 'not_supported';
            return false;
        }

        if (!('PushManager' in window)) {
            console.log('[Push] Push notifications are not supported');
            this.initError = 'not_supported';
            return false;
        }

        if (!('Notification' in window)) {
            console.log('[Push] Notifications are not supported');
            this.initError = 'not_supported';
            return false;
        }

        try {
            // Get service worker registration
            console.log('[Push] Waiting for service worker...');
            this.swRegistration = await navigator.serviceWorker.ready;
            console.log('[Push] Service worker is ready:', this.swRegistration.scope);

            // Fetch VAPID public key from server
            const keyUrl = this.basePath + '/api/push/key';
            console.log('[Push] Fetching public key from:', keyUrl);
            const response = await fetch(keyUrl);
            console.log('[Push] Key response status:', response.status, response.statusText);

            if (!response.ok) {
                const errorText = await response.text();
                console.log('[Push] Push key fetch failed:', response.status, errorText);
                this.initError = 'not_enabled';
                return false;
            }

            const data = await response.json();
            console.log('[Push] Key response data:', {
                hasPublicKey: !!data.publicKey,
                publicKeyLength: data.publicKey ? data.publicKey.length : 0,
                error: data.error || null
            });

            if (!data.publicKey) {
                console.log('[Push] No public key returned from server');
                this.initError = 'not_configured';
                return false;
            }
            this.publicKey = data.publicKey;
            console.log('[Push] Public key received, length:', this.publicKey.length);

            // Check current subscription status
            await this.updateSubscriptionStatus();

            this.isInitialized = true;
            console.log('[Push] Initialization complete - isInitialized:', this.isInitialized, 'isSubscribed:', this.isSubscribed);
            return true;
        } catch (error) {
            console.error('[Push] Initialization error:', error);
            console.error('[Push] Error details:', {
                name: error.name,
                message: error.message,
                stack: error.stack
            });
            this.initError = 'init_failed';
            return false;
        }
    }

    /**
     * Update the current subscription status
     */
    async updateSubscriptionStatus() {
        try {
            const subscription = await this.swRegistration.pushManager.getSubscription();
            this.isSubscribed = subscription !== null;
            console.log('[Push] Subscription status:', this.isSubscribed ? 'Subscribed' : 'Not subscribed');
        } catch (error) {
            console.error('[Push] Error checking subscription:', error);
            this.isSubscribed = false;
        }
    }

    /**
     * Request notification permission and subscribe to push
     * @returns {Promise<boolean>} True if subscription successful
     */
    async subscribe() {
        console.log('[Push] Starting subscription process...');
        console.log('[Push] Current permission:', Notification.permission);
        console.log('[Push] Has public key:', !!this.publicKey);
        console.log('[Push] Has SW registration:', !!this.swRegistration);

        try {
            // Request notification permission
            console.log('[Push] Requesting notification permission...');
            const permission = await Notification.requestPermission();
            console.log('[Push] Permission result:', permission);

            if (permission !== 'granted') {
                console.log('[Push] Notification permission denied');
                return false;
            }

            console.log('[Push] Notification permission granted');

            // Subscribe to push
            console.log('[Push] Attempting to subscribe to push manager...');
            console.log('[Push] Public key length:', this.publicKey ? this.publicKey.length : 0);

            const applicationServerKey = this.urlBase64ToUint8Array(this.publicKey);
            console.log('[Push] Application server key created, length:', applicationServerKey.length);

            const subscription = await this.swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            });

            console.log('[Push] User is subscribed');
            console.log('[Push] Subscription endpoint:', subscription.endpoint);
            console.log('[Push] Subscription JSON:', JSON.stringify(subscription.toJSON()));

            // Send subscription to server
            const subscribeUrl = this.basePath + '/api/push/subscribe';
            const csrfToken = this.getCsrfToken();
            console.log('[Push] Sending subscription to server:', subscribeUrl);
            console.log('[Push] CSRF token present:', !!csrfToken);

            const response = await fetch(subscribeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    subscription: subscription.toJSON()
                })
            });

            console.log('[Push] Server response status:', response.status, response.statusText);

            if (!response.ok) {
                const errorData = await response.json();
                console.error('[Push] Server error response:', errorData);
                throw new Error(errorData.error || 'Failed to save subscription');
            }

            const successData = await response.json();
            console.log('[Push] Server success response:', successData);

            this.isSubscribed = true;
            console.log('[Push] Subscription saved to server successfully');
            return true;
        } catch (error) {
            console.error('[Push] Subscription error:', error);
            console.error('[Push] Error details:', {
                name: error.name,
                message: error.message,
                stack: error.stack
            });
            return false;
        }
    }

    /**
     * Unsubscribe from push notifications
     * @returns {Promise<boolean>} True if unsubscription successful
     */
    async unsubscribe() {
        try {
            const subscription = await this.swRegistration.pushManager.getSubscription();

            if (!subscription) {
                this.isSubscribed = false;
                return true;
            }

            // Unsubscribe locally
            await subscription.unsubscribe();

            // Remove from server
            const response = await fetch(this.basePath + '/api/push/unsubscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                body: JSON.stringify({
                    endpoint: subscription.endpoint
                })
            });

            if (!response.ok) {
                console.warn('[Push] Server unsubscribe failed, but local unsubscribe succeeded');
            }

            this.isSubscribed = false;
            console.log('[Push] User unsubscribed');
            return true;
        } catch (error) {
            console.error('[Push] Unsubscribe error:', error);
            return false;
        }
    }

    /**
     * Toggle subscription status
     * @returns {Promise<boolean>} New subscription status
     */
    async toggle() {
        if (this.isSubscribed) {
            await this.unsubscribe();
        } else {
            await this.subscribe();
        }
        return this.isSubscribed;
    }

    /**
     * Check if notifications are supported
     * @returns {boolean}
     */
    isSupported() {
        return 'serviceWorker' in navigator &&
               'PushManager' in window &&
               'Notification' in window;
    }

    /**
     * Get current notification permission status
     * @returns {string} 'granted', 'denied', or 'default'
     */
    getPermissionStatus() {
        if (!('Notification' in window)) {
            return 'unsupported';
        }
        return Notification.permission;
    }

    /**
     * Check if user is currently subscribed
     * @returns {boolean}
     */
    getSubscriptionStatus() {
        return this.isSubscribed;
    }

    /**
     * Convert URL-safe Base64 to Uint8Array for applicationServerKey
     * @param {string} base64String - Base64 URL-safe encoded string
     * @returns {Uint8Array}
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    /**
     * Get CSRF token from meta tag or input field
     * @returns {string}
     */
    getCsrfToken() {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            return metaTag.content;
        }

        const inputField = document.querySelector('input[name="_csrf_token"]');
        if (inputField) {
            return inputField.value;
        }

        return '';
    }

    /**
     * Send a test notification (admin only)
     * @returns {Promise<boolean>}
     */
    async sendTest() {
        try {
            const response = await fetch(this.basePath + '/api/push/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                }
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Failed to send test notification');
            }

            console.log('[Push] Test notification sent');
            return true;
        } catch (error) {
            console.error('[Push] Test notification error:', error);
            return false;
        }
    }
}

// ============================================================================
// UI Helper Functions
// ============================================================================

/**
 * Initialize push notification UI components
 */
function initPushUI() {
    const toggleButton = document.getElementById('push-toggle');
    const statusText = document.getElementById('push-status');

    if (!toggleButton) {
        return;
    }

    // Update UI based on current state
    const updateUI = () => {
        // Check if browser supports push notifications
        if (!window.pukePush.isSupported()) {
            toggleButton.disabled = true;
            toggleButton.textContent = 'Not Supported';
            if (statusText) {
                statusText.textContent = 'Push notifications are not supported in this browser';
            }
            return;
        }

        // Check if push handler was initialized successfully
        if (!window.pukePush.isInitialized) {
            toggleButton.disabled = true;

            // Show appropriate message based on error type
            switch (window.pukePush.initError) {
                case 'not_enabled':
                case 'not_configured':
                    toggleButton.textContent = 'Not Available';
                    if (statusText) {
                        statusText.textContent = 'Push notifications are not configured on this server';
                    }
                    break;
                case 'init_failed':
                    toggleButton.textContent = 'Unavailable';
                    if (statusText) {
                        statusText.textContent = 'Failed to initialize push notifications. Try refreshing the page.';
                    }
                    break;
                default:
                    toggleButton.textContent = 'Not Available';
                    if (statusText) {
                        statusText.textContent = 'Push notifications are not available';
                    }
            }
            return;
        }

        const permission = window.pukePush.getPermissionStatus();
        if (permission === 'denied') {
            toggleButton.disabled = true;
            toggleButton.textContent = 'Blocked';
            if (statusText) {
                statusText.textContent = 'Notifications are blocked. Please enable them in your browser settings.';
            }
            return;
        }

        const isSubscribed = window.pukePush.getSubscriptionStatus();
        toggleButton.disabled = false;
        toggleButton.textContent = isSubscribed ? 'Disable Notifications' : 'Enable Notifications';
        toggleButton.classList.toggle('subscribed', isSubscribed);

        if (statusText) {
            statusText.textContent = isSubscribed
                ? 'You will receive push notifications'
                : 'Enable notifications to stay updated';
        }
    };

    // Handle toggle button click
    toggleButton.addEventListener('click', async () => {
        // Safety check - don't try to subscribe if not initialized
        if (!window.pukePush.isInitialized) {
            console.error('[Push] Cannot toggle - push handler not initialized');
            return;
        }

        toggleButton.disabled = true;
        toggleButton.textContent = 'Please wait...';

        try {
            await window.pukePush.toggle();
        } catch (error) {
            console.error('[Push] Toggle error:', error);
            if (window.App) {
                window.App.showToast('Failed to update notification settings', 'error');
            }
        }

        updateUI();
    });

    // Initial UI update
    updateUI();
}

// ============================================================================
// Initialize on DOM Ready
// ============================================================================

// Create global instance with a unique name that won't conflict with browser APIs
window.pukePush = new PushNotificationHandler();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', async () => {
        await window.pukePush.init();
        initPushUI();
    });
} else {
    window.pukePush.init().then(() => {
        initPushUI();
    });
}
