import { defineConfig, devices } from '@playwright/test';

/**
 * Puke Portal - Playwright E2E Test Configuration
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  // Test directory
  testDir: './tests/e2e',

  // Test timeout
  timeout: 30000,

  // Expect timeout
  expect: {
    timeout: 5000,
  },

  // Run tests in parallel
  fullyParallel: true,

  // Fail the build on CI if you accidentally left test.only in the source code
  forbidOnly: !!process.env.CI,

  // Retry on CI only
  retries: process.env.CI ? 2 : 0,

  // Opt out of parallel tests on CI
  workers: process.env.CI ? 1 : undefined,

  // Reporter configuration
  reporter: [
    ['html', { outputFolder: 'tests/e2e-report', open: 'never' }],
    ['json', { outputFile: 'tests/e2e-results.json' }],
    ['list'],
  ],

  // Shared settings for all projects
  use: {
    // Base URL for tests - uses /pp subdirectory to match production deployment
    baseURL: 'http://localhost:8080/pp',

    // Collect trace on failure
    trace: 'on-first-retry',

    // Take screenshot on failure
    screenshot: 'only-on-failure',

    // Record video on failure
    video: 'on-first-retry',

    // Headless mode (set to false for debugging)
    headless: true,
  },

  // Test projects for different browsers
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile-chrome',
      use: { ...devices['Pixel 5'] },
    },
  ],

  // Web server configuration - start PHP server with testing config
  // Uses router.php to simulate /pp subdirectory deployment
  webServer: {
    command: 'APP_ENV=testing php -S localhost:8080 tests/router.php',
    cwd: '/Users/Jon.White/Documents/github/pp/portal',
    url: 'http://localhost:8080/pp/health',
    reuseExistingServer: !process.env.CI,
    timeout: 120000,
    stdout: 'pipe',
    stderr: 'pipe',
  },

  // Output folder for test artifacts
  outputDir: 'tests/e2e-artifacts',
});
