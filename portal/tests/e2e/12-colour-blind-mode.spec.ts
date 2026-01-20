import { test, expect } from './fixtures/auth';

/**
 * Colour Blind Mode Tests
 * Tests for Issue #23 - Accessibility colour blind mode toggle
 */

test.describe('Colour Blind Mode', () => {
  test.describe('Profile Page Toggle', () => {
    test('colour blind mode toggle is visible on own profile', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Check that the accessibility card exists
      const accessibilityCard = page.locator('.accessibility-card');
      await expect(accessibilityCard).toBeVisible();

      // Check that the toggle switch container is visible (the checkbox itself is hidden for styling)
      const toggleSwitch = page.locator('.toggle-switch');
      await expect(toggleSwitch).toBeVisible();

      // Check the label text
      const label = page.locator('.setting-label');
      await expect(label).toContainText('Colour Blind Mode');
    });

    test('toggle changes data attribute on html element', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Get initial state
      const initialValue = await page.locator('html').getAttribute('data-color-blind-mode');
      expect(initialValue).toBe('false');

      // Click the toggle switch (the label element, not the hidden checkbox)
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();

      // Wait for the attribute to change
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Toggle it back off
      await toggleSwitch.click();
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'false');
    });

    test('colour blind mode persists after page reload', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Enable colour blind mode
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();

      // Wait for API call to complete
      await page.waitForResponse(response =>
        response.url().includes('/api/members/') &&
        response.url().includes('/preferences')
      );

      // Reload the page
      await page.reload();

      // Check that colour blind mode is still enabled
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');
      await expect(page.locator('#color-blind-toggle')).toBeChecked();

      // Clean up: disable it
      await toggleSwitch.click();
      await page.waitForResponse(response =>
        response.url().includes('/api/members/') &&
        response.url().includes('/preferences')
      );
    });
  });

  test.describe('CSS Variables Override', () => {
    test('colour blind mode changes primary colour from red to blue', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Get the header colour before enabling colour blind mode
      const headerBefore = await page.locator('.app-header').evaluate(el => {
        return window.getComputedStyle(el).backgroundColor;
      });

      // Enable colour blind mode
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();

      // Wait for attribute to change
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Get the header colour after enabling colour blind mode
      const headerAfter = await page.locator('.app-header').evaluate(el => {
        return window.getComputedStyle(el).backgroundColor;
      });

      // Colours should be different
      expect(headerBefore).not.toBe(headerAfter);

      // The blue colour #0077BB = rgb(0, 119, 187)
      expect(headerAfter).toContain('0, 119, 187');

      // Clean up
      await toggleSwitch.click();
    });

    test('profile header gradient changes with colour blind mode', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Enable colour blind mode
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();

      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Check that profile header has the new gradient
      const profileHeader = page.locator('.profile-header');
      const background = await profileHeader.evaluate(el => {
        return window.getComputedStyle(el).background;
      });

      // Should contain the blue colour
      expect(background).toContain('0, 119, 187');

      // Clean up
      await toggleSwitch.click();
    });
  });

  test.describe('Feedback Messages', () => {
    test('shows success message when enabling colour blind mode', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      const toggleSwitch = page.locator('.toggle-switch');
      const description = page.locator('.accessibility-setting .setting-description');

      // Enable colour blind mode
      await toggleSwitch.click();

      // Check for success message
      await expect(description).toContainText('Colour blind mode enabled!');

      // Wait for message to revert
      await page.waitForTimeout(2500);

      // Check it reverted to original text
      await expect(description).toContainText('Adjusts colours throughout the app');

      // Clean up
      await toggleSwitch.click();
    });

    test('shows success message when disabling colour blind mode', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      const toggleSwitch = page.locator('.toggle-switch');
      const description = page.locator('.accessibility-setting .setting-description');

      // Enable first
      await toggleSwitch.click();
      await page.waitForTimeout(2500); // Wait for first message to clear

      // Now disable
      await toggleSwitch.click();

      // Check for disabled message
      await expect(description).toContainText('Colour blind mode disabled.');

      // Wait for message to revert
      await page.waitForTimeout(2500);

      // Check it reverted to original text
      await expect(description).toContainText('Adjusts colours throughout the app');
    });
  });

  test.describe('API Endpoint', () => {
    test('PUT /api/members/{id}/preferences saves colour blind mode', async ({ firefighterPage: page }) => {
      await page.goto('/profile');

      // Get the member ID from the toggle
      const memberId = await page.locator('#color-blind-toggle').getAttribute('data-member-id');

      // Make a direct API call
      const response = await page.evaluate(async (id) => {
        const res = await fetch(`/pp/api/members/${id}/preferences`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ color_blind_mode: true })
        });
        return { status: res.status, ok: res.ok };
      }, memberId);

      expect(response.ok).toBe(true);

      // Clean up
      await page.evaluate(async (id) => {
        await fetch(`/pp/api/members/${id}/preferences`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ color_blind_mode: false })
        });
      }, memberId);
    });
  });

  test.describe('Cross-Page Consistency', () => {
    test('colour blind mode applies to home page', async ({ firefighterPage: page }) => {
      // Enable colour blind mode on profile
      await page.goto('/profile');
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Navigate to home
      await page.goto('/');

      // Check that the data attribute is still set
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Check header colour
      const headerColour = await page.locator('.app-header').evaluate(el => {
        return window.getComputedStyle(el).backgroundColor;
      });
      expect(headerColour).toContain('0, 119, 187');

      // Clean up - go back to profile and disable
      await page.goto('/profile');
      await toggleSwitch.click();
    });

    test('colour blind mode applies to calendar page', async ({ firefighterPage: page }) => {
      // Enable colour blind mode on profile
      await page.goto('/profile');
      const toggleSwitch = page.locator('.toggle-switch');
      await toggleSwitch.click();
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Navigate to calendar
      await page.goto('/calendar');

      // Check that the data attribute is still set
      await expect(page.locator('html')).toHaveAttribute('data-color-blind-mode', 'true');

      // Clean up
      await page.goto('/profile');
      await toggleSwitch.click();
    });
  });
});
