import { Page, expect } from '@playwright/test';

/**
 * Test Helpers for Puke Portal E2E Tests
 */

export interface TestUser {
  id: number;
  email: string;
  name: string;
  role: 'firefighter' | 'officer' | 'admin' | 'superadmin';
  brigadeId: number;
}

/**
 * Wait for page to be fully loaded
 */
export async function waitForPageLoad(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Login as a test user via magic link simulation
 * This bypasses the email step by directly accessing with a test token
 */
export async function loginAsUser(page: Page, email: string): Promise<void> {
  // Navigate to the test login endpoint
  await page.goto(`/auth/test-login?email=${encodeURIComponent(email)}`);
  await waitForPageLoad(page);
}

/**
 * Login via the login page with magic link flow simulation
 */
export async function loginViaUI(page: Page, email: string): Promise<void> {
  await page.goto('/auth/login');
  await page.fill('input[name="email"]', email);
  await page.click('button[type="submit"]');
  await waitForPageLoad(page);
}

/**
 * Check if user is authenticated
 */
export async function isAuthenticated(page: Page): Promise<boolean> {
  // Check for authenticated-only elements
  const userMenu = await page.locator('.user-menu, .user-name, [data-user]').count();
  return userMenu > 0;
}

/**
 * Logout the current user
 */
export async function logout(page: Page): Promise<void> {
  await page.goto('/auth/logout');
  await waitForPageLoad(page);
}

/**
 * Navigate to a page and wait for it to load
 */
export async function navigateTo(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await waitForPageLoad(page);
}

/**
 * Check for console errors
 */
export async function checkForConsoleErrors(page: Page): Promise<string[]> {
  const errors: string[] = [];

  page.on('console', msg => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });

  return errors;
}

/**
 * Take a screenshot with a descriptive name
 */
export async function takeScreenshot(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: `tests/e2e-artifacts/screenshots/${name}.png`, fullPage: true });
}

/**
 * Check if an element exists
 */
export async function elementExists(page: Page, selector: string): Promise<boolean> {
  const count = await page.locator(selector).count();
  return count > 0;
}

/**
 * Wait for a toast/flash message
 */
export async function waitForToast(page: Page, expectedText?: string): Promise<void> {
  const toastSelector = '.toast, .flash, .alert, .notification, .message';
  await page.waitForSelector(toastSelector, { timeout: 5000 });

  if (expectedText) {
    await expect(page.locator(toastSelector)).toContainText(expectedText);
  }
}

/**
 * Fill a form with data
 */
export async function fillForm(page: Page, formData: Record<string, string>): Promise<void> {
  for (const [name, value] of Object.entries(formData)) {
    const input = page.locator(`[name="${name}"]`);
    const tagName = await input.evaluate(el => el.tagName.toLowerCase());

    if (tagName === 'select') {
      await input.selectOption(value);
    } else if (tagName === 'textarea') {
      await input.fill(value);
    } else {
      const inputType = await input.getAttribute('type');
      if (inputType === 'checkbox' || inputType === 'radio') {
        if (value === 'true' || value === '1') {
          await input.check();
        } else {
          await input.uncheck();
        }
      } else {
        await input.fill(value);
      }
    }
  }
}

/**
 * Submit a form and wait for response
 */
export async function submitForm(page: Page, submitSelector = 'button[type="submit"]'): Promise<void> {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
    page.click(submitSelector),
  ]);
}

/**
 * Check HTTP response status
 */
export async function checkResponseStatus(page: Page, expectedStatus: number): Promise<void> {
  const response = await page.waitForResponse(resp => resp.status() === expectedStatus);
  expect(response.status()).toBe(expectedStatus);
}

/**
 * Get all validation errors displayed on the page
 */
export async function getValidationErrors(page: Page): Promise<string[]> {
  const errorSelector = '.error, .invalid-feedback, .validation-error, .field-error';
  const errors = await page.locator(errorSelector).allTextContents();
  return errors.filter(e => e.trim().length > 0);
}

/**
 * Check mobile responsiveness
 */
export async function checkMobileLayout(page: Page): Promise<{
  hasHamburgerMenu: boolean;
  noHorizontalScroll: boolean;
  touchTargetsAdequate: boolean;
}> {
  // Set mobile viewport
  await page.setViewportSize({ width: 375, height: 667 });
  await waitForPageLoad(page);

  // Check for hamburger menu
  const hasHamburgerMenu = await elementExists(page, '.hamburger, .mobile-menu-toggle, [data-mobile-menu]');

  // Check for horizontal scroll
  const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
  const viewportWidth = await page.evaluate(() => window.innerWidth);
  const noHorizontalScroll = bodyWidth <= viewportWidth;

  // Check touch targets (min 44px)
  const touchTargetsAdequate = await page.evaluate(() => {
    const buttons = document.querySelectorAll('button, a, input[type="submit"], .btn');
    return Array.from(buttons).every(btn => {
      const rect = btn.getBoundingClientRect();
      return rect.width >= 44 && rect.height >= 44;
    });
  });

  return { hasHamburgerMenu, noHorizontalScroll, touchTargetsAdequate };
}

/**
 * Setup test database with initial data
 */
export async function setupTestData(page: Page): Promise<void> {
  // Call the test setup endpoint
  await page.goto('/api/test/setup');
  await waitForPageLoad(page);
}

/**
 * Cleanup test data
 */
export async function cleanupTestData(page: Page): Promise<void> {
  // Call the test cleanup endpoint
  await page.goto('/api/test/cleanup');
  await waitForPageLoad(page);
}
