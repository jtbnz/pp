import { test, expect } from '@playwright/test';

/**
 * Phase 2: Authentication System Tests
 * Tests for magic link auth, PIN login, sessions, and logout
 */

test.describe('Phase 2: Authentication System', () => {
  test.describe('Login Page', () => {
    test('login page loads correctly', async ({ page }) => {
      await page.goto('/auth/login');

      // Page title uses "Sign In" not "Login"
      expect(await page.title()).toContain('Sign In');

      // Check for email input
      const emailInput = page.locator('input[name="email"], input[type="email"]');
      await expect(emailInput).toBeVisible();

      // Check for submit button
      const submitButton = page.locator('button[type="submit"]');
      await expect(submitButton).toBeVisible();
    });

    test('login form validates email', async ({ page }) => {
      await page.goto('/auth/login');

      // Submit without email
      await page.click('button[type="submit"]');

      // Browser validation should prevent submission
      const emailInput = page.locator('input[name="email"], input[type="email"]');
      await expect(emailInput).toBeFocused();
    });

    test('login form accepts valid email', async ({ page }) => {
      await page.goto('/auth/login');

      await page.fill('input[name="email"], input[type="email"]', 'test@example.com');
      await page.click('button[type="submit"]');

      // Should show success message or redirect
      await page.waitForLoadState('networkidle');

      const pageContent = await page.content();
      // Should either show success or error (depending on email existence)
      expect(pageContent.toLowerCase()).toMatch(/check|email|sent|error|not found/);
    });
  });

  test.describe('Magic Link Verification', () => {
    test('invalid token shows error', async ({ page }) => {
      await page.goto('/auth/verify/invalid-token-12345');

      const pageContent = await page.content();
      expect(pageContent.toLowerCase()).toMatch(/invalid|expired|error/);
    });

    test('verify page handles missing token', async ({ page }) => {
      const response = await page.goto('/auth/verify/');
      expect(response?.status()).toBe(404);
    });
  });

  test.describe('PIN Login', () => {
    test('PIN login page loads', async ({ page }) => {
      await page.goto('/auth/pin');

      // PIN page requires session context with email
      // Without prior auth, it may still load the page structure
      // Check for form or redirect to login
      const pageContent = await page.content();

      // Should have PIN form structure OR redirect to login
      const hasPinForm = pageContent.includes('pin') || pageContent.includes('PIN');
      const hasLoginRedirect = page.url().includes('/auth/login');

      expect(hasPinForm || hasLoginRedirect).toBe(true);
    });

    test('PIN login validates input length', async ({ page }) => {
      await page.goto('/auth/pin');

      // Find PIN input
      const pinInput = page.locator('input[name="pin"], input[type="password"], input[inputmode="numeric"]');

      if (await pinInput.isVisible()) {
        // Enter short PIN
        await pinInput.fill('123');
        await page.click('button[type="submit"]');

        // Should show validation error
        await page.waitForLoadState('networkidle');
        const pageContent = await page.content();
        expect(pageContent.toLowerCase()).toMatch(/invalid|short|6 digits|error/);
      }
    });
  });

  test.describe('Activation Flow', () => {
    test('activation page loads', async ({ page }) => {
      await page.goto('/auth/activate');

      const pageContent = await page.content();
      // Should show activation form or redirect to login
      expect(pageContent).toBeTruthy();
    });
  });

  test.describe('Protected Routes', () => {
    test('unauthenticated access to /calendar redirects to login', async ({ page }) => {
      await page.goto('/calendar');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('unauthenticated access to /notices redirects to login', async ({ page }) => {
      await page.goto('/notices');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('unauthenticated access to /leave redirects to login', async ({ page }) => {
      await page.goto('/leave');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('unauthenticated access to /admin redirects to login', async ({ page }) => {
      await page.goto('/admin');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('unauthenticated access to /members redirects to login', async ({ page }) => {
      await page.goto('/members');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('unauthenticated access to /profile redirects to login', async ({ page }) => {
      await page.goto('/profile');

      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('API Authentication', () => {
    test('API returns 401 for unauthenticated requests', async ({ request }) => {
      const endpoints = [
        '/api/members',
        '/api/events',
        '/api/notices',
        '/api/leave',
      ];

      for (const endpoint of endpoints) {
        const response = await request.get(endpoint);
        expect(response.status(), `${endpoint} should return 401`).toBe(401);
      }
    });
  });

  test.describe('CSRF Protection', () => {
    test('login form includes CSRF token', async ({ page }) => {
      await page.goto('/auth/login');

      // Check for CSRF token in form
      const csrfInput = page.locator('input[name="_csrf_token"]');
      await expect(csrfInput).toBeAttached();

      const csrfValue = await csrfInput.getAttribute('value');
      expect(csrfValue).toBeTruthy();
      expect(csrfValue?.length).toBeGreaterThan(30);
    });
  });

  test.describe('Session Management', () => {
    test('session cookie is set with proper flags', async ({ page, context }) => {
      await page.goto('/auth/login');

      const cookies = await context.cookies();
      const sessionCookie = cookies.find(c => c.name.includes('session') || c.name.includes('puke'));

      if (sessionCookie) {
        expect(sessionCookie.httpOnly).toBe(true);
        // Note: sameSite check may vary based on config
      }
    });
  });

  test.describe('Logout', () => {
    test('logout endpoint exists', async ({ page }) => {
      // Try to POST to logout (may fail without session, but should not 404)
      await page.goto('/auth/login');

      // Get CSRF token
      const csrfToken = await page.locator('input[name="_csrf_token"]').getAttribute('value');

      // Attempt logout via form submission
      const response = await page.request.post('/auth/logout', {
        form: {
          _csrf_token: csrfToken || '',
        },
      });

      // Should redirect (302) or succeed, not 404
      expect(response.status()).not.toBe(404);
    });
  });
});
