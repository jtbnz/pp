import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Phase 1: Project Foundation Tests
 * Tests for basic project setup, routing, and infrastructure
 */

test.describe('Phase 1: Project Foundation', () => {
  test.describe('Server and Routing', () => {
    test('server responds to requests', async ({ page }) => {
      const response = await page.goto('/');
      expect(response?.status()).toBe(200);
    });

    test('health endpoint returns OK', async ({ page }) => {
      const response = await page.goto('/health');
      expect(response?.status()).toBe(200);

      const body = await page.textContent('body');
      const json = JSON.parse(body || '{}');

      expect(json.status).toBe('ok');
      expect(json.timezone).toBe('Pacific/Auckland');
    });

    test('manifest.json is served correctly', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      expect(response?.status()).toBe(200);

      const contentType = response?.headers()['content-type'];
      expect(contentType).toContain('application/manifest+json');

      const body = await page.textContent('body');
      const manifest = JSON.parse(body || '{}');

      expect(manifest.name).toContain('Puke');
      expect(manifest.display).toBe('standalone');
      expect(manifest.icons).toBeDefined();
      expect(manifest.icons.length).toBeGreaterThan(0);
    });

    test('404 page is shown for non-existent routes', async ({ page }) => {
      const response = await page.goto('/this-page-does-not-exist');
      expect(response?.status()).toBe(404);
    });

    test('OPTIONS requests are handled (CORS preflight)', async ({ request }) => {
      const response = await request.fetch('/api/members', {
        method: 'OPTIONS',
        headers: {
          'Origin': 'http://localhost:8080',
          'Access-Control-Request-Method': 'GET',
        },
      });

      expect(response.status()).toBe(204);
      expect(response.headers()['access-control-allow-methods']).toContain('GET');
    });
  });

  test.describe('Static Assets', () => {
    test('CSS file loads', async ({ page }) => {
      const response = await page.goto('/assets/css/app.css');
      expect(response?.status()).toBe(200);

      const contentType = response?.headers()['content-type'];
      expect(contentType).toContain('text/css');
    });

    test('JavaScript file loads', async ({ page }) => {
      const response = await page.goto('/assets/js/app.js');
      expect(response?.status()).toBe(200);

      const contentType = response?.headers()['content-type'];
      expect(contentType).toContain('javascript');
    });

    test('SVG icons exist', async ({ page }) => {
      const icons = [
        '/assets/icons/icon-72.svg',
        '/assets/icons/icon-192.svg',
        '/assets/icons/icon-512.svg',
      ];

      for (const icon of icons) {
        const response = await page.goto(icon);
        expect(response?.status(), `Icon ${icon} should exist`).toBe(200);
      }
    });
  });

  test.describe('Database and Schema', () => {
    test('database tables exist', async ({ page }) => {
      // This tests that the application can interact with the database
      // by verifying that pages that require database access work
      await page.goto('/');

      // If database isn't set up properly, this would error
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Database connection failed');
      expect(pageContent).not.toContain('PDOException');
    });
  });

  test.describe('Timezone Configuration', () => {
    test('health endpoint reports Pacific/Auckland timezone', async ({ page }) => {
      await page.goto('/health');

      const body = await page.textContent('body');
      const json = JSON.parse(body || '{}');

      expect(json.timezone).toBe('Pacific/Auckland');
    });
  });

  test.describe('Error Handling', () => {
    test('API 404 returns JSON error', async ({ request }) => {
      const response = await request.get('/api/nonexistent');
      expect(response.status()).toBe(404);

      const json = await response.json();
      expect(json.error).toBeDefined();
    });

    test('unauthorized API access returns 401', async ({ request }) => {
      const response = await request.get('/api/members');
      expect(response.status()).toBe(401);

      const json = await response.json();
      expect(json.error).toContain('Unauthorized');
    });
  });

  test.describe('Accessibility - Basic', () => {
    test('home page has no critical accessibility violations', async ({ page }) => {
      await page.goto('/');

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();

      const criticalViolations = results.violations.filter(
        v => v.impact === 'critical' || v.impact === 'serious'
      );

      expect(criticalViolations, 'No critical accessibility violations').toHaveLength(0);
    });
  });
});
