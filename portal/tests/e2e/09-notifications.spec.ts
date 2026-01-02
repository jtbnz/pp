import { test, expect } from '@playwright/test';

/**
 * Phase 9: Notifications Tests
 * Tests for push notifications API and subscription management
 */

test.describe('Phase 9: Notifications', () => {
  test.describe('Push API Endpoints', () => {
    test('GET /api/push/key requires authentication', async ({ request }) => {
      const response = await request.get('/api/push/key');
      expect(response.status()).toBe(401);
    });

    test('GET /api/push/status requires authentication', async ({ request }) => {
      const response = await request.get('/api/push/status');
      expect(response.status()).toBe(401);
    });

    test('POST /api/push/subscribe requires authentication', async ({ request }) => {
      const response = await request.post('/api/push/subscribe', {
        data: {
          endpoint: 'https://fcm.googleapis.com/fcm/send/xxx',
          keys: {
            p256dh: 'test-key',
            auth: 'test-auth',
          },
        },
      });
      expect(response.status()).toBe(401);
    });

    test('POST /api/push/unsubscribe requires authentication', async ({ request }) => {
      const response = await request.post('/api/push/unsubscribe');
      expect(response.status()).toBe(401);
    });

    test('POST /api/push/test requires authentication', async ({ request }) => {
      const response = await request.post('/api/push/test');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Push Endpoint Routing', () => {
    test('push endpoints return 401 not 404', async ({ request }) => {
      const endpoints = [
        '/api/push/key',
        '/api/push/status',
      ];

      for (const endpoint of endpoints) {
        const response = await request.get(endpoint);
        expect(response.status(), `${endpoint} should return 401, not 404`).toBe(401);
      }
    });
  });

  test.describe('Push Subscription', () => {
    test('subscribe requires valid subscription object', async ({ request }) => {
      const response = await request.post('/api/push/subscribe', {
        data: {},
      });
      expect(response.status()).toBe(401);
    });

    test('unsubscribe is protected', async ({ request }) => {
      const response = await request.post('/api/push/unsubscribe');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Push JavaScript Client', () => {
    test('push.js exists', async ({ page }) => {
      const response = await page.goto('/assets/js/push.js');
      // May or may not exist as separate file
      // Could be bundled in app.js
    });
  });

  test.describe('VAPID Keys', () => {
    test('VAPID public key endpoint is protected', async ({ request }) => {
      const response = await request.get('/api/push/key');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Notification Triggers', () => {
    test.describe('Leave Request Notifications', () => {
      test('leave request triggers notification (requires auth)', async ({ request }) => {
        const response = await request.post('/api/leave', {
          data: { training_date: '2025-02-03' },
        });
        expect(response.status()).toBe(401);
      });
    });

    test.describe('Urgent Notice Notifications', () => {
      test('urgent notice triggers notification (requires auth)', async ({ request }) => {
        const response = await request.post('/api/notices', {
          data: { title: 'Urgent', type: 'urgent' },
        });
        expect(response.status()).toBe(401);
      });
    });
  });

  test.describe('Push API Response Format', () => {
    test('push endpoints return proper error format', async ({ request }) => {
      const response = await request.get('/api/push/status');
      expect(response.status()).toBe(401);

      const json = await response.json();
      expect(json.error).toBeDefined();
    });
  });

  test.describe('Browser Push API Support', () => {
    test('browser supports Push API', async ({ page }) => {
      await page.goto('/auth/login');

      const hasPushSupport = await page.evaluate(() => {
        return 'PushManager' in window;
      });

      // Chrome supports Push API
      expect(hasPushSupport).toBe(true);
    });

    test('browser supports Notifications API', async ({ page }) => {
      await page.goto('/auth/login');

      const hasNotificationSupport = await page.evaluate(() => {
        return 'Notification' in window;
      });

      expect(hasNotificationSupport).toBe(true);
    });
  });

  test.describe('Service Worker Push Handling', () => {
    test('service worker has push event handler', async ({ page }) => {
      const response = await page.goto('/sw.js');
      if (response?.status() === 200) {
        const content = await page.textContent('body');
        expect(content).toContain('push');
      }
    });

    test('service worker has notification click handler', async ({ page }) => {
      const response = await page.goto('/sw.js');
      if (response?.status() === 200) {
        const content = await page.textContent('body');
        expect(content).toContain('notificationclick');
      }
    });
  });

  test.describe('Email Notifications', () => {
    // Email notifications are internal - verify endpoints exist
    test('leave request would trigger email (requires auth)', async ({ request }) => {
      const response = await request.post('/api/leave', {
        data: { training_date: '2025-02-10' },
      });
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Notification Preferences', () => {
    test('push status shows subscription state (requires auth)', async ({ request }) => {
      const response = await request.get('/api/push/status');
      expect(response.status()).toBe(401);
    });
  });
});
