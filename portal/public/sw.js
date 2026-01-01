/**
 * Puke Portal - Service Worker
 *
 * Handles caching, offline support, background sync, and push notifications.
 */

'use strict';

// ============================================================================
// Configuration
// ============================================================================

const CACHE_NAME = 'puke-portal-v1';
const API_CACHE_NAME = 'puke-portal-api-v1';

// Derive base path from service worker scope
// e.g., if scope is 'https://kiaora.tech/pp/', basePath is '/pp'
const BASE_PATH = new URL(self.registration?.scope || self.location.href).pathname.replace(/\/$/, '') || '';

// Assets to precache on install (with base path)
const PRECACHE_ASSETS = [
    BASE_PATH + '/',
    BASE_PATH + '/offline.html',
    BASE_PATH + '/assets/css/app.css',
    BASE_PATH + '/assets/js/app.js',
    BASE_PATH + '/assets/js/offline-storage.js',
    BASE_PATH + '/assets/js/push.js',
    BASE_PATH + '/manifest.json',
    BASE_PATH + '/assets/icons/icon-192.png',
    BASE_PATH + '/assets/icons/icon-512.png',
    BASE_PATH + '/assets/icons/badge-72.png'
];

// API routes that should be network-only
const API_ROUTES = [
    BASE_PATH + '/api/',
    BASE_PATH + '/auth/'
];

// Cache-first static assets
const STATIC_EXTENSIONS = [
    '.css',
    '.js',
    '.png',
    '.jpg',
    '.jpeg',
    '.gif',
    '.svg',
    '.webp',
    '.woff',
    '.woff2',
    '.ico'
];

// ============================================================================
// Install Event - Precache Assets
// ============================================================================

self.addEventListener('install', (event) => {
    console.log('[SW] Install event');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Precaching assets');
                return cache.addAll(PRECACHE_ASSETS);
            })
            .then(() => {
                // Force the waiting service worker to become active
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Precache failed:', error);
            })
    );
});

// ============================================================================
// Activate Event - Clean Old Caches
// ============================================================================

self.addEventListener('activate', (event) => {
    console.log('[SW] Activate event');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => {
                            // Delete old versions of our caches
                            return name.startsWith('puke-portal-') &&
                                   name !== CACHE_NAME &&
                                   name !== API_CACHE_NAME;
                        })
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                // Take control of all pages immediately
                return self.clients.claim();
            })
    );
});

// ============================================================================
// Fetch Event - Cache Strategy
// ============================================================================

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') {
        // For non-GET requests, try network and queue if offline
        event.respondWith(handleNonGetRequest(request));
        return;
    }

    // Skip cross-origin requests
    if (url.origin !== location.origin) {
        return;
    }

    // API requests - Network only, fall back to cached if available
    if (isApiRequest(url.pathname)) {
        event.respondWith(handleApiRequest(request));
        return;
    }

    // HTML pages - Network first, fall back to cache, then offline page
    if (request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(handleHtmlRequest(request));
        return;
    }

    // Static assets - Cache first
    if (isStaticAsset(url.pathname)) {
        event.respondWith(handleStaticAsset(request));
        return;
    }

    // Default - Network first with cache fallback
    event.respondWith(handleDefaultRequest(request));
});

// ============================================================================
// Request Handlers
// ============================================================================

/**
 * Handle non-GET requests (POST, PUT, DELETE, etc.)
 * If offline, queue for later sync
 */
async function handleNonGetRequest(request) {
    try {
        return await fetch(request);
    } catch (error) {
        // Network failed - queue for background sync if supported
        if ('sync' in self.registration) {
            await queueRequest(request);
            return new Response(
                JSON.stringify({
                    success: true,
                    queued: true,
                    message: 'Request queued for sync when online'
                }),
                {
                    headers: { 'Content-Type': 'application/json' },
                    status: 202
                }
            );
        }

        return new Response(
            JSON.stringify({
                success: false,
                error: 'You are offline'
            }),
            {
                headers: { 'Content-Type': 'application/json' },
                status: 503
            }
        );
    }
}

/**
 * Handle API requests - Network only with offline queue
 */
async function handleApiRequest(request) {
    try {
        const response = await fetch(request);

        // Cache successful GET responses
        if (response.ok) {
            const cache = await caches.open(API_CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        // Try to return cached API response
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        return new Response(
            JSON.stringify({
                success: false,
                error: 'You are offline',
                offline: true
            }),
            {
                headers: { 'Content-Type': 'application/json' },
                status: 503
            }
        );
    }
}

/**
 * Handle HTML page requests - Network first, cache fallback, offline page
 */
async function handleHtmlRequest(request) {
    try {
        const response = await fetch(request);

        // Cache successful responses
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        // Try cache
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        // Return offline page
        const offlinePage = await caches.match('/offline.html');
        if (offlinePage) {
            return offlinePage;
        }

        // Fallback response
        return new Response(
            '<html><body><h1>Offline</h1><p>Please check your connection.</p></body></html>',
            {
                headers: { 'Content-Type': 'text/html' },
                status: 503
            }
        );
    }
}

/**
 * Handle static assets - Cache first, network fallback
 */
async function handleStaticAsset(request) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
        return cachedResponse;
    }

    try {
        const response = await fetch(request);

        // Cache successful responses
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        // Return placeholder for images if needed
        if (request.url.match(/\.(png|jpg|jpeg|gif|svg|webp)$/)) {
            return new Response('', { status: 404 });
        }

        throw error;
    }
}

/**
 * Handle default requests - Network first with cache fallback
 */
async function handleDefaultRequest(request) {
    try {
        const response = await fetch(request);

        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        throw error;
    }
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Check if request is for an API endpoint
 */
function isApiRequest(pathname) {
    return API_ROUTES.some(route => pathname.startsWith(route));
}

/**
 * Check if request is for a static asset
 */
function isStaticAsset(pathname) {
    return STATIC_EXTENSIONS.some(ext => pathname.endsWith(ext));
}

/**
 * Queue a request for later sync using IndexedDB
 */
async function queueRequest(request) {
    const db = await openDB();
    const tx = db.transaction('pending-requests', 'readwrite');
    const store = tx.objectStore('pending-requests');

    const requestData = {
        url: request.url,
        method: request.method,
        headers: Object.fromEntries(request.headers.entries()),
        body: await request.clone().text(),
        timestamp: Date.now()
    };

    await store.add(requestData);
    await tx.complete;
}

/**
 * Open IndexedDB for request queue
 */
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('puke-portal-sw', 1);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains('pending-requests')) {
                db.createObjectStore('pending-requests', {
                    keyPath: 'id',
                    autoIncrement: true
                });
            }
        };
    });
}

// ============================================================================
// Background Sync
// ============================================================================

self.addEventListener('sync', (event) => {
    console.log('[SW] Sync event:', event.tag);

    if (event.tag === 'sync-pending-data') {
        event.waitUntil(syncPendingRequests());
    }
});

/**
 * Process all queued requests
 */
async function syncPendingRequests() {
    try {
        const db = await openDB();
        const tx = db.transaction('pending-requests', 'readwrite');
        const store = tx.objectStore('pending-requests');

        const requests = await new Promise((resolve, reject) => {
            const request = store.getAll();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        console.log('[SW] Processing', requests.length, 'pending requests');

        for (const requestData of requests) {
            try {
                const response = await fetch(requestData.url, {
                    method: requestData.method,
                    headers: requestData.headers,
                    body: requestData.body || undefined
                });

                if (response.ok) {
                    // Remove successful request from queue
                    const deleteTx = db.transaction('pending-requests', 'readwrite');
                    const deleteStore = deleteTx.objectStore('pending-requests');
                    await new Promise((resolve, reject) => {
                        const request = deleteStore.delete(requestData.id);
                        request.onsuccess = () => resolve();
                        request.onerror = () => reject(request.error);
                    });

                    console.log('[SW] Synced request:', requestData.url);
                }
            } catch (error) {
                console.error('[SW] Failed to sync request:', requestData.url, error);
            }
        }

        // Notify clients that sync is complete
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_COMPLETE',
                timestamp: Date.now()
            });
        });
    } catch (error) {
        console.error('[SW] Sync failed:', error);
    }
}

// ============================================================================
// Push Notifications
// ============================================================================

self.addEventListener('push', (event) => {
    console.log('[SW] Push event received');

    // Default notification data
    let notificationData = {
        title: 'Puke Fire Portal',
        body: 'You have a new notification',
        icon: '/assets/icons/icon-192.png',
        badge: '/assets/icons/badge-72.png',
        tag: 'puke-portal-notification',
        data: {
            url: '/'
        }
    };

    // Parse push payload if available
    if (event.data) {
        try {
            const payload = event.data.json();
            notificationData = {
                title: payload.title || notificationData.title,
                body: payload.body || notificationData.body,
                icon: payload.icon || notificationData.icon,
                badge: payload.badge || notificationData.badge,
                tag: payload.tag || payload.data?.type || notificationData.tag,
                data: {
                    ...notificationData.data,
                    ...(payload.data || {}),
                    timestamp: payload.timestamp || Date.now()
                }
            };
        } catch (e) {
            // If JSON parsing fails, use text as body
            notificationData.body = event.data.text();
        }
    }

    // Notification options
    const options = {
        body: notificationData.body,
        icon: notificationData.icon,
        badge: notificationData.badge,
        tag: notificationData.tag,
        data: notificationData.data,
        vibrate: [200, 100, 200],
        requireInteraction: notificationData.data?.type === 'urgent' || false,
        timestamp: notificationData.data?.timestamp || Date.now(),
        actions: [
            {
                action: 'open',
                title: 'Open'
            },
            {
                action: 'dismiss',
                title: 'Dismiss'
            }
        ]
    };

    // Add specific actions based on notification type
    if (notificationData.data?.type === 'leave_request') {
        options.actions = [
            { action: 'review', title: 'Review' },
            { action: 'dismiss', title: 'Later' }
        ];
    } else if (notificationData.data?.type === 'leave_decision') {
        options.actions = [
            { action: 'view', title: 'View' },
            { action: 'dismiss', title: 'OK' }
        ];
    } else if (notificationData.data?.type === 'urgent') {
        options.actions = [
            { action: 'open', title: 'View Now' }
        ];
        options.requireInteraction = true;
    }

    event.waitUntil(
        self.registration.showNotification(notificationData.title, options)
    );
});

self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification click:', event.notification.tag, 'action:', event.action);

    const notification = event.notification;
    const action = event.action;
    const data = notification.data || {};

    // Close the notification
    notification.close();

    // Handle dismiss action - just close, don't open anything
    if (action === 'dismiss') {
        return;
    }

    // Determine URL to open based on action and notification type
    let urlToOpen = data.url || (BASE_PATH + '/');

    if (action === 'review' && data.type === 'leave_request') {
        urlToOpen = BASE_PATH + '/leave/pending';
    } else if (action === 'view' && data.type === 'leave_decision') {
        urlToOpen = BASE_PATH + '/leave';
    } else if (data.type === 'urgent' && data.noticeId) {
        urlToOpen = BASE_PATH + '/notices/' + data.noticeId;
    } else if (data.type === 'training_reminder' && data.eventId) {
        urlToOpen = BASE_PATH + '/calendar?event=' + data.eventId;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Check if there's already a window open at our origin
                for (const client of clientList) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        // Navigate existing window and focus it
                        return client.navigate(urlToOpen).then(() => client.focus());
                    }
                }

                // No existing window, open a new one
                if (self.clients.openWindow) {
                    return self.clients.openWindow(urlToOpen);
                }
            })
    );
});

self.addEventListener('notificationclose', (event) => {
    console.log('[SW] Notification closed:', event.notification.tag);
});

// ============================================================================
// Message Handler - Communication with main thread
// ============================================================================

self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);

    switch (event.data.type) {
        case 'SKIP_WAITING':
            self.skipWaiting();
            break;

        case 'CLEAR_CACHE':
            event.waitUntil(
                caches.keys().then((cacheNames) => {
                    return Promise.all(
                        cacheNames.map((name) => caches.delete(name))
                    );
                }).then(() => {
                    event.ports[0]?.postMessage({ success: true });
                })
            );
            break;

        case 'GET_CACHE_STATUS':
            event.waitUntil(
                caches.keys().then((cacheNames) => {
                    event.ports[0]?.postMessage({
                        caches: cacheNames,
                        version: CACHE_NAME
                    });
                })
            );
            break;

        default:
            console.log('[SW] Unknown message type:', event.data.type);
    }
});
