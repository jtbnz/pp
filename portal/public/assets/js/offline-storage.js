/**
 * Puke Portal - Offline Storage
 *
 * IndexedDB wrapper for caching data and queuing requests when offline.
 * ES6+ with no dependencies.
 */

'use strict';

// ============================================================================
// OfflineStorage Class
// ============================================================================

class OfflineStorage {
    /**
     * Create an OfflineStorage instance
     * @param {string} dbName - Database name
     * @param {number} version - Database version
     */
    constructor(dbName = 'puke-portal', version = 1) {
        this.dbName = dbName;
        this.version = version;
        this.db = null;
        this.isOpen = false;
    }

    /**
     * Open the database connection
     * @returns {Promise<IDBDatabase>}
     */
    async open() {
        if (this.isOpen && this.db) {
            return this.db;
        }

        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.version);

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to open database:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                this.isOpen = true;
                console.log('[OfflineStorage] Database opened successfully');
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                this.setupStores(db);
            };

            request.onblocked = () => {
                console.warn('[OfflineStorage] Database blocked - close other tabs');
            };
        });
    }

    /**
     * Set up object stores during database upgrade
     * @param {IDBDatabase} db
     */
    setupStores(db) {
        // Store for cached data with TTL
        if (!db.objectStoreNames.contains('cache')) {
            const cacheStore = db.createObjectStore('cache', { keyPath: 'key' });
            cacheStore.createIndex('expires', 'expires', { unique: false });
        }

        // Store for pending requests (offline queue)
        if (!db.objectStoreNames.contains('pending-requests')) {
            const requestStore = db.createObjectStore('pending-requests', {
                keyPath: 'id',
                autoIncrement: true
            });
            requestStore.createIndex('timestamp', 'timestamp', { unique: false });
            requestStore.createIndex('url', 'url', { unique: false });
        }

        // Store for user preferences
        if (!db.objectStoreNames.contains('preferences')) {
            db.createObjectStore('preferences', { keyPath: 'key' });
        }
    }

    /**
     * Close the database connection
     */
    close() {
        if (this.db) {
            this.db.close();
            this.db = null;
            this.isOpen = false;
            console.log('[OfflineStorage] Database closed');
        }
    }

    // ========================================================================
    // Cache Operations
    // ========================================================================

    /**
     * Cache data with optional TTL
     * @param {string} key - Cache key
     * @param {*} data - Data to cache (must be serializable)
     * @param {number} ttl - Time to live in milliseconds (0 = no expiry)
     * @returns {Promise<void>}
     */
    async cacheData(key, data, ttl = 0) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('cache', 'readwrite');
            const store = transaction.objectStore('cache');

            const entry = {
                key,
                data,
                timestamp: Date.now(),
                expires: ttl > 0 ? Date.now() + ttl : 0
            };

            const request = store.put(entry);

            request.onsuccess = () => {
                console.log('[OfflineStorage] Cached:', key);
                resolve();
            };

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to cache:', key, request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get cached data
     * @param {string} key - Cache key
     * @returns {Promise<*|null>} - Cached data or null if not found/expired
     */
    async getCached(key) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('cache', 'readonly');
            const store = transaction.objectStore('cache');
            const request = store.get(key);

            request.onsuccess = () => {
                const entry = request.result;

                if (!entry) {
                    resolve(null);
                    return;
                }

                // Check if expired
                if (entry.expires > 0 && entry.expires < Date.now()) {
                    // Delete expired entry
                    this.deleteCached(key).catch(() => {});
                    resolve(null);
                    return;
                }

                resolve(entry.data);
            };

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to get cached:', key, request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Delete cached data
     * @param {string} key - Cache key
     * @returns {Promise<void>}
     */
    async deleteCached(key) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('cache', 'readwrite');
            const store = transaction.objectStore('cache');
            const request = store.delete(key);

            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clear all cached data
     * @returns {Promise<void>}
     */
    async clearCache() {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('cache', 'readwrite');
            const store = transaction.objectStore('cache');
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[OfflineStorage] Cache cleared');
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clean up expired cache entries
     * @returns {Promise<number>} - Number of entries deleted
     */
    async cleanExpiredCache() {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('cache', 'readwrite');
            const store = transaction.objectStore('cache');
            const index = store.index('expires');
            const now = Date.now();

            // Get all entries with expires > 0 (has expiry) and < now (expired)
            const range = IDBKeyRange.bound(1, now);
            const request = index.openCursor(range);
            let deleteCount = 0;

            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    cursor.delete();
                    deleteCount++;
                    cursor.continue();
                } else {
                    console.log('[OfflineStorage] Cleaned', deleteCount, 'expired entries');
                    resolve(deleteCount);
                }
            };

            request.onerror = () => reject(request.error);
        });
    }

    // ========================================================================
    // Request Queue Operations
    // ========================================================================

    /**
     * Queue a request for later sync
     * @param {string} url - Request URL
     * @param {string} method - HTTP method
     * @param {Object} headers - Request headers
     * @param {*} body - Request body
     * @returns {Promise<number>} - Queue entry ID
     */
    async queueRequest(url, method, headers = {}, body = null) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readwrite');
            const store = transaction.objectStore('pending-requests');

            const entry = {
                url,
                method,
                headers,
                body: body ? JSON.stringify(body) : null,
                timestamp: Date.now(),
                retries: 0
            };

            const request = store.add(entry);

            request.onsuccess = () => {
                console.log('[OfflineStorage] Queued request:', method, url);
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('[OfflineStorage] Failed to queue request:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get all pending requests
     * @returns {Promise<Array>}
     */
    async getPendingRequests() {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readonly');
            const store = transaction.objectStore('pending-requests');
            const request = store.getAll();

            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get pending request count
     * @returns {Promise<number>}
     */
    async getPendingCount() {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readonly');
            const store = transaction.objectStore('pending-requests');
            const request = store.count();

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Remove a pending request
     * @param {number} id - Request ID
     * @returns {Promise<void>}
     */
    async removePendingRequest(id) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readwrite');
            const store = transaction.objectStore('pending-requests');
            const request = store.delete(id);

            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Update retry count for a pending request
     * @param {number} id - Request ID
     * @returns {Promise<void>}
     */
    async incrementRetry(id) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readwrite');
            const store = transaction.objectStore('pending-requests');
            const request = store.get(id);

            request.onsuccess = () => {
                const entry = request.result;
                if (entry) {
                    entry.retries = (entry.retries || 0) + 1;
                    store.put(entry);
                }
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clear all pending requests
     * @returns {Promise<void>}
     */
    async clearPendingRequests() {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('pending-requests', 'readwrite');
            const store = transaction.objectStore('pending-requests');
            const request = store.clear();

            request.onsuccess = () => {
                console.log('[OfflineStorage] Pending requests cleared');
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Process all pending requests
     * @param {number} maxRetries - Maximum retry attempts before removing
     * @returns {Promise<{success: number, failed: number}>}
     */
    async processPendingRequests(maxRetries = 3) {
        const requests = await this.getPendingRequests();
        let success = 0;
        let failed = 0;

        console.log('[OfflineStorage] Processing', requests.length, 'pending requests');

        for (const request of requests) {
            try {
                // Skip if max retries exceeded
                if (request.retries >= maxRetries) {
                    console.warn('[OfflineStorage] Max retries exceeded for:', request.url);
                    await this.removePendingRequest(request.id);
                    failed++;
                    continue;
                }

                const response = await fetch(request.url, {
                    method: request.method,
                    headers: request.headers,
                    body: request.body || undefined
                });

                if (response.ok) {
                    await this.removePendingRequest(request.id);
                    success++;
                    console.log('[OfflineStorage] Synced:', request.method, request.url);
                } else {
                    throw new Error(`HTTP ${response.status}`);
                }
            } catch (error) {
                console.error('[OfflineStorage] Sync failed:', request.url, error);
                await this.incrementRetry(request.id);
                failed++;
            }
        }

        return { success, failed };
    }

    // ========================================================================
    // Preferences Operations
    // ========================================================================

    /**
     * Set a preference
     * @param {string} key - Preference key
     * @param {*} value - Preference value
     * @returns {Promise<void>}
     */
    async setPreference(key, value) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('preferences', 'readwrite');
            const store = transaction.objectStore('preferences');
            const request = store.put({ key, value });

            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get a preference
     * @param {string} key - Preference key
     * @param {*} defaultValue - Default value if not found
     * @returns {Promise<*>}
     */
    async getPreference(key, defaultValue = null) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('preferences', 'readonly');
            const store = transaction.objectStore('preferences');
            const request = store.get(key);

            request.onsuccess = () => {
                const entry = request.result;
                resolve(entry ? entry.value : defaultValue);
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Delete a preference
     * @param {string} key - Preference key
     * @returns {Promise<void>}
     */
    async deletePreference(key) {
        await this.open();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction('preferences', 'readwrite');
            const store = transaction.objectStore('preferences');
            const request = store.delete(key);

            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    // ========================================================================
    // Utility Methods
    // ========================================================================

    /**
     * Check if IndexedDB is supported
     * @returns {boolean}
     */
    static isSupported() {
        return 'indexedDB' in window;
    }

    /**
     * Get storage usage estimate
     * @returns {Promise<{usage: number, quota: number}>}
     */
    static async getStorageEstimate() {
        if ('storage' in navigator && 'estimate' in navigator.storage) {
            return navigator.storage.estimate();
        }
        return { usage: 0, quota: 0 };
    }

    /**
     * Request persistent storage
     * @returns {Promise<boolean>}
     */
    static async requestPersistentStorage() {
        if ('storage' in navigator && 'persist' in navigator.storage) {
            return navigator.storage.persist();
        }
        return false;
    }
}

// ============================================================================
// Singleton Instance
// ============================================================================

const offlineStorage = new OfflineStorage();

// Clean up expired cache periodically
if (typeof window !== 'undefined') {
    // Clean on load
    window.addEventListener('load', () => {
        setTimeout(() => {
            offlineStorage.cleanExpiredCache().catch(() => {});
        }, 5000);
    });

    // Clean every 30 minutes while page is active
    setInterval(() => {
        if (document.visibilityState === 'visible') {
            offlineStorage.cleanExpiredCache().catch(() => {});
        }
    }, 30 * 60 * 1000);
}

// Export for use in other modules
window.OfflineStorage = OfflineStorage;
window.offlineStorage = offlineStorage;
