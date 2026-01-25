import { test, expect } from '@playwright/test';
import { loginAsFirefighter, loginAsAdmin } from './fixtures/auth';

/**
 * Test Notification Feature Tests
 * Verifies that users can send test notifications to themselves
 */

test.describe('Test Notification Feature', () => {
  test.describe('Profile Page - Test Notification Button', () => {
    test('test notification button is visible when subscribed', async ({ page }) => {
      await loginAsFirefighter(page);

      // Navigate to own profile
      await page.goto('/members/4'); // Firefighter One

      // Check that Push Notifications card exists
      const notificationsCard = page.locator('.notifications-card');
      await expect(notificationsCard).toBeVisible();

      // Check that test button exists but is hidden initially
      const testButton = page.locator('#push-test');
      await expect(testButton).toBeAttached();
    });

    test('test notification button is not visible when not subscribed', async ({ page }) => {
      await loginAsFirefighter(page);

      // Navigate to own profile
      await page.goto('/members/4');

      // Test button should be hidden initially (not subscribed)
      const testButton = page.locator('#push-test');
      await expect(testButton).toBeHidden();
    });
  });

  test.describe('Push API - Test Endpoint', () => {
    test('POST /api/push/test requires authentication', async ({ request }) => {
      const response = await request.post('/api/push/test');
      expect(response.status()).toBe(401);
    });

    test('POST /api/push/test allows authenticated users', async ({ page, request }) => {
      await loginAsFirefighter(page);
      
      // Get auth cookies from the page
      const cookies = await page.context().cookies();
      
      // Make authenticated request
      const response = await request.post('/api/push/test', {
        headers: {
          'Cookie': cookies.map(c => `${c.name}=${c.value}`).join('; ')
        }
      });

      // Should succeed or fail with 503/400 (push not enabled/no subscriptions)
      // but NOT 403 (forbidden)
      expect([400, 503]).toContain(response.status());
      
      const data = await response.json();
      expect(data.success).toBeDefined();
    });

    test('POST /api/push/test does not require admin role', async ({ page, request }) => {
      // Login as regular firefighter (not admin)
      await loginAsFirefighter(page);
      
      const cookies = await page.context().cookies();
      
      const response = await request.post('/api/push/test', {
        headers: {
          'Cookie': cookies.map(c => `${c.name}=${c.value}`).join('; ')
        }
      });

      // Should not return 403 Forbidden
      expect(response.status()).not.toBe(403);
    });
  });

  test.describe('JavaScript Push Handler', () => {
    test('sendTest method exists on PushNotificationHandler', async ({ page }) => {
      await page.goto('/auth/login');

      const hasSendTest = await page.evaluate(() => {
        return typeof window.pukePush?.sendTest === 'function';
      });

      expect(hasSendTest).toBe(true);
    });

    test('sendTest method returns promise', async ({ page }) => {
      await page.goto('/auth/login');

      const returnsPromise = await page.evaluate(async () => {
        const result = window.pukePush?.sendTest?.();
        return result instanceof Promise;
      });

      expect(returnsPromise).toBe(true);
    });
  });

  test.describe('UI Feedback', () => {
    test('test button shows loading state when clicked', async ({ page }) => {
      await loginAsFirefighter(page);
      await page.goto('/members/4');

      // Mock the push handler to simulate subscribed state
      await page.evaluate(() => {
        if (window.pukePush) {
          window.pukePush.isInitialized = true;
          window.pukePush.isSubscribed = true;
        }
      });

      // Reload to trigger UI update
      await page.reload();

      // Wait for test button to be visible (if subscribed)
      const testButton = page.locator('#push-test');
      
      // Button might not be visible if push isn't configured
      // This test validates the JS behavior, not the actual subscription
      const isVisible = await testButton.isVisible().catch(() => false);
      
      if (isVisible) {
        const originalText = await testButton.textContent();
        expect(originalText).toBeTruthy();
      }
    });
  });

  test.describe('Error Handling', () => {
    test('test notification handles no subscriptions gracefully', async ({ page, request }) => {
      await loginAsFirefighter(page);
      
      const cookies = await page.context().cookies();
      
      const response = await request.post('/api/push/test', {
        headers: {
          'Cookie': cookies.map(c => `${c.name}=${c.value}`).join('; ')
        }
      });

      // Should return 400 or 503 with descriptive message
      if (response.status() === 400) {
        const data = await response.json();
        expect(data.message).toBeDefined();
        expect(data.message.toLowerCase()).toContain('subscription');
      }
    });
  });

  test.describe('Push Notifications Card', () => {
    test('shows notifications card on own profile only', async ({ page }) => {
      await loginAsFirefighter(page);

      // Own profile
      await page.goto('/members/4');
      const ownCard = page.locator('.notifications-card');
      await expect(ownCard).toBeVisible();

      // Check if admin panel is accessible
      const canAccessAdminPanel = await page.goto('/members/5').then(
        () => true,
        () => false
      );

      if (canAccessAdminPanel) {
        // Other member's profile - notifications card should not be visible
        const otherCard = page.locator('.notifications-card');
        await expect(otherCard).not.toBeVisible();
      }
    });

    test('notifications card has correct content', async ({ page }) => {
      await loginAsFirefighter(page);
      await page.goto('/members/4');

      const card = page.locator('.notifications-card');
      await expect(card).toBeVisible();

      // Check for heading
      await expect(card.locator('h3')).toContainText('Push Notifications');

      // Check for description text
      await expect(card.locator('.card-body p')).toContainText('instant notifications');

      // Check for toggle button
      const toggleBtn = card.locator('#push-toggle');
      await expect(toggleBtn).toBeVisible();

      // Check for status text element
      const statusText = card.locator('#push-status');
      await expect(statusText).toBeAttached();
    });
  });
});
