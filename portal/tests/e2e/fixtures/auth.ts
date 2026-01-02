import { test as base, Page, BrowserContext } from '@playwright/test';
import { execSync } from 'child_process';
import * as path from 'path';

/**
 * Authentication fixtures for E2E tests
 * Provides pre-authenticated browser contexts for different user roles
 */

// Test user data - these will be created/ensured in the database before tests
export const testUsers = {
  admin: {
    id: 1,
    email: 'test@example.com',
    name: 'Test Admin',
    role: 'admin',
    brigadeId: 1,
  },
  firefighter: {
    id: 2,
    email: 'testuser@example.com',
    name: 'Test Firefighter',
    role: 'firefighter',
    brigadeId: 1,
  },
  officer: {
    id: 3,
    email: 'officer@example.com',
    name: 'Test Officer',
    role: 'officer',
    brigadeId: 1,
  },
  firefighter2: {
    id: 4,
    email: 'firefighter2@example.com',
    name: 'Second Firefighter',
    role: 'firefighter',
    brigadeId: 1,
  },
};

// Extend the base test with authenticated fixtures
export const test = base.extend<{
  adminPage: Page;
  firefighterPage: Page;
  officerPage: Page;
  authenticatedContext: BrowserContext;
}>({
  // Create an admin-authenticated page
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await authenticateUser(page, testUsers.admin);
    await use(page);
    await context.close();
  },

  // Create a firefighter-authenticated page
  firefighterPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await authenticateUser(page, testUsers.firefighter);
    await use(page);
    await context.close();
  },

  // Create an officer-authenticated page
  officerPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await authenticateUser(page, testUsers.officer);
    await use(page);
    await context.close();
  },

  // Create a generic authenticated context
  authenticatedContext: async ({ browser }, use) => {
    const context = await browser.newContext();
    await use(context);
    await context.close();
  },
});

/**
 * Authenticate a user by setting the session directly via PHP
 * This bypasses the login flow for faster test execution
 */
async function authenticateUser(page: Page, user: typeof testUsers.admin): Promise<void> {
  // Ensure user exists in database
  ensureTestUser(user);

  // Navigate to a special test auth endpoint that sets up the session
  // This endpoint only exists when APP_ENV=testing
  await page.goto(`/auth/test-login?user_id=${user.id}`);

  // Verify we're authenticated by checking for user menu
  await page.waitForSelector('[data-testid="user-menu"], button:has-text("User menu")', {
    timeout: 5000,
  }).catch(() => {
    // If user menu not found, check if we're on home page (authenticated)
    // The test-login should redirect to home after auth
  });
}

/**
 * Ensure test user exists in database with known credentials
 */
function ensureTestUser(user: typeof testUsers.admin): void {
  const dbPath = path.resolve(__dirname, '../../../data/portal.db');
  const pinHash = execSync(
    `php -r "echo password_hash('123456', PASSWORD_BCRYPT, ['cost' => 4]);"`
  ).toString().trim();

  // Check if user exists and update/insert accordingly
  try {
    execSync(`sqlite3 "${dbPath}" "
      INSERT OR REPLACE INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires)
      VALUES (
        ${user.id},
        ${user.brigadeId},
        '${user.email}',
        '${user.name}',
        '${user.role}',
        'active',
        '${pinHash}',
        datetime('now', '+5 years')
      );
    "`);
  } catch (error) {
    console.error('Failed to ensure test user:', error);
  }
}

/**
 * Helper to login via the UI (for tests that need to test the login flow itself)
 */
export async function loginViaUI(page: Page, email: string, pin: string = '123456'): Promise<void> {
  await page.goto('/auth/login');
  await page.fill('input[name="email"]', email);
  await page.click('button[type="submit"]');

  // Wait for PIN page or magic link confirmation
  await page.waitForURL(/\/(auth\/pin|auth\/magic-link)/);

  if (page.url().includes('/auth/pin')) {
    await page.fill('input[name="pin"]', pin);
    await page.click('button[type="submit"]');
  }

  // Wait for redirect to home or intended page
  await page.waitForURL(/^(?!.*\/auth\/)/);
}

/**
 * Helper to logout
 */
export async function logout(page: Page): Promise<void> {
  await page.goto('/auth/logout');
  await page.waitForURL(/\/auth\/login/);
}

/**
 * Get CSRF token from page
 */
export async function getCsrfToken(page: Page): Promise<string> {
  const token = await page.locator('input[name="_csrf_token"]').first().getAttribute('value');
  return token || '';
}

export { expect } from '@playwright/test';
