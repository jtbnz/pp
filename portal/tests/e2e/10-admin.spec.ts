import { test, expect } from '@playwright/test';

/**
 * Phase 10: Admin Dashboard Tests
 * Tests for admin panel, member management, event/notice/leave management
 */

test.describe('Phase 10: Admin Dashboard', () => {
  test.describe('Admin Dashboard Page', () => {
    test('admin dashboard requires authentication', async ({ page }) => {
      await page.goto('/admin');
      await page.waitForURL(/\/auth\/login/);
    });

    test('admin route returns proper redirect', async ({ page }) => {
      const response = await page.goto('/admin');
      // Should redirect to login
      expect(page.url()).toContain('/auth/login');
    });
  });

  test.describe('Admin Member Management', () => {
    test('admin members list requires authentication', async ({ page }) => {
      await page.goto('/admin/members');
      await page.waitForURL(/\/auth\/login/);
    });

    test('admin invite form requires authentication', async ({ page }) => {
      await page.goto('/admin/members/invite');
      await page.waitForURL(/\/auth\/login/);
    });

    test('admin member edit requires authentication', async ({ page }) => {
      await page.goto('/admin/members/1');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Event Management', () => {
    test('admin events list requires authentication', async ({ page }) => {
      await page.goto('/admin/events');
      await page.waitForURL(/\/auth\/login/);
    });

    test('admin create event form requires authentication', async ({ page }) => {
      await page.goto('/admin/events/create');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Notice Management', () => {
    test('admin notices list requires authentication', async ({ page }) => {
      await page.goto('/admin/notices');
      await page.waitForURL(/\/auth\/login/);
    });

    test('admin create notice form requires authentication', async ({ page }) => {
      await page.goto('/admin/notices/create');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Leave Management', () => {
    test('admin leave list requires authentication', async ({ page }) => {
      await page.goto('/admin/leave');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Settings', () => {
    test('admin settings page requires authentication', async ({ page }) => {
      await page.goto('/admin/settings');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin API Routes', () => {
    test('admin routes exist and require auth', async ({ request }) => {
      const adminRoutes = [
        '/admin',
        '/admin/members',
        '/admin/events',
        '/admin/notices',
        '/admin/leave',
        '/admin/settings',
      ];

      for (const route of adminRoutes) {
        const response = await request.get(route, {
          maxRedirects: 0,
        });
        // Should redirect (302) or require login
        expect([302, 401, 403]).toContain(response.status());
      }
    });
  });

  test.describe('Admin POST Routes', () => {
    test('admin invite POST requires authentication', async ({ request }) => {
      const response = await request.post('/admin/members/invite', {
        form: {
          email: 'new@test.com',
          name: 'New User',
          role: 'firefighter',
        },
        maxRedirects: 0,
      });
      // Expect redirect to login, or 401/403 for CSRF/auth rejection
      expect([302, 401, 403]).toContain(response.status());
    });

    test('admin create event POST requires authentication', async ({ request }) => {
      const response = await request.post('/admin/events', {
        form: {
          title: 'New Event',
          start_time: '2025-02-01 19:00:00',
        },
        maxRedirects: 0,
      });
      expect([302, 401, 403]).toContain(response.status());
    });

    test('admin create notice POST requires authentication', async ({ request }) => {
      const response = await request.post('/admin/notices', {
        form: {
          title: 'New Notice',
          content: 'Content',
          type: 'standard',
        },
        maxRedirects: 0,
      });
      expect([302, 401, 403]).toContain(response.status());
    });
  });

  test.describe('Admin Role Check', () => {
    test('admin routes check for admin role', async ({ page }) => {
      // Without auth, should redirect to login
      await page.goto('/admin');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Dashboard Stats', () => {
    test('dashboard would show stats (requires auth)', async ({ page }) => {
      await page.goto('/admin');
      // Without auth, just verify redirect
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Activity Log', () => {
    test('dashboard would show activity (requires auth)', async ({ page }) => {
      await page.goto('/admin');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Quick Actions', () => {
    test('invite member action requires auth', async ({ page }) => {
      await page.goto('/admin/members/invite');
      await page.waitForURL(/\/auth\/login/);
    });

    test('create event action requires auth', async ({ page }) => {
      await page.goto('/admin/events/create');
      await page.waitForURL(/\/auth\/login/);
    });

    test('create notice action requires auth', async ({ page }) => {
      await page.goto('/admin/notices/create');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Admin Send Login Link', () => {
    test('send login link requires authentication', async ({ request }) => {
      const response = await request.post('/admin/members/1/send-login-link', {
        form: {},
        maxRedirects: 0,
      });
      // Expect redirect to login or 401/403
      expect([302, 401, 403]).toContain(response.status());
    });
  });

  test.describe('Admin Update Settings', () => {
    test('update settings requires authentication', async ({ request }) => {
      const response = await request.put('/admin/settings', {
        form: {
          setting_key: 'value',
        },
        maxRedirects: 0,
      });
      // Expect redirect to login or 401/403
      expect([302, 401, 403]).toContain(response.status());
    });
  });

  test.describe('Admin Member Update', () => {
    test('update member requires authentication', async ({ request }) => {
      const response = await request.put('/admin/members/1', {
        form: {
          name: 'Updated Name',
        },
        maxRedirects: 0,
      });
      // Expect redirect to login or 401/403
      expect([302, 401, 403]).toContain(response.status());
    });

    test('update member via POST with _method requires auth', async ({ request }) => {
      const response = await request.post('/admin/members/1', {
        form: {
          _method: 'PUT',
          name: 'Updated Name',
        },
        maxRedirects: 0,
      });
      // Expect redirect to login or 401/403
      expect([302, 401, 403]).toContain(response.status());
    });
  });
});
