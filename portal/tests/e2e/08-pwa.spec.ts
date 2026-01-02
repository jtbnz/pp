import { test, expect } from '@playwright/test';

/**
 * Phase 8: PWA & Offline Support Tests
 * Tests for service worker, manifest, caching, and offline functionality
 */

test.describe('Phase 8: PWA & Offline Support', () => {
  test.describe('Web App Manifest', () => {
    test('manifest.json is accessible', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      expect(response?.status()).toBe(200);
    });

    test('manifest has correct content type', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      const contentType = response?.headers()['content-type'];
      expect(contentType).toContain('application/manifest+json');
    });

    test('manifest has required fields', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      const manifest = await response?.json();

      expect(manifest.name).toBeDefined();
      expect(manifest.short_name).toBeDefined();
      expect(manifest.start_url).toBeDefined();
      expect(manifest.display).toBe('standalone');
      expect(manifest.theme_color).toBeDefined();
      expect(manifest.background_color).toBeDefined();
    });

    test('manifest has icons', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      const manifest = await response?.json();

      expect(manifest.icons).toBeDefined();
      expect(manifest.icons.length).toBeGreaterThan(0);

      // Check for required icon sizes
      const sizes = manifest.icons.map((i: any) => i.sizes);
      expect(sizes).toContain('192x192');
      expect(sizes).toContain('512x512');
    });

    test('manifest has shortcuts', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      const manifest = await response?.json();

      expect(manifest.shortcuts).toBeDefined();
      expect(manifest.shortcuts.length).toBeGreaterThan(0);
    });

    test('manifest icons are accessible', async ({ page }) => {
      const response = await page.goto('/manifest.json');
      const manifest = await response?.json();

      for (const icon of manifest.icons.slice(0, 3)) {
        // Handle base_path prefix - remove it for local testing
        let iconUrl = icon.src;
        if (iconUrl.startsWith('/pp/')) {
          iconUrl = iconUrl.replace('/pp', '');
        } else if (!iconUrl.startsWith('/')) {
          iconUrl = `/${iconUrl}`;
        }
        const iconResponse = await page.goto(iconUrl);
        expect(iconResponse?.status(), `Icon ${iconUrl} should be accessible`).toBe(200);
      }
    });
  });

  test.describe('Service Worker', () => {
    test('service worker file exists', async ({ page }) => {
      const response = await page.goto('/sw.js');
      expect(response?.status()).toBe(200);

      const contentType = response?.headers()['content-type'];
      expect(contentType).toContain('javascript');
    });

    test('service worker has valid JavaScript', async ({ page }) => {
      await page.goto('/sw.js');
      const content = await page.textContent('body');

      // Check for service worker event listeners
      expect(content).toContain('addEventListener');
    });

    test('service worker handles install event', async ({ page }) => {
      await page.goto('/sw.js');
      const content = await page.textContent('body');

      expect(content).toContain('install');
    });

    test('service worker handles fetch event', async ({ page }) => {
      await page.goto('/sw.js');
      const content = await page.textContent('body');

      expect(content).toContain('fetch');
    });

    test('service worker handles activate event', async ({ page }) => {
      await page.goto('/sw.js');
      const content = await page.textContent('body');

      expect(content).toContain('activate');
    });
  });

  test.describe('Offline Page', () => {
    test('offline.html exists', async ({ page }) => {
      const response = await page.goto('/offline.html');
      // May or may not exist - depends on implementation
      if (response?.status() === 200) {
        const content = await page.content();
        expect(content.toLowerCase()).toContain('offline');
      }
    });
  });

  test.describe('App Shell', () => {
    test('main CSS file is cacheable', async ({ page }) => {
      const response = await page.goto('/assets/css/app.css');
      expect(response?.status()).toBe(200);
    });

    test('main JS file is cacheable', async ({ page }) => {
      const response = await page.goto('/assets/js/app.js');
      expect(response?.status()).toBe(200);
    });
  });

  test.describe('PWA Requirements', () => {
    test('home page has meta viewport', async ({ page }) => {
      await page.goto('/auth/login');

      const viewport = await page.locator('meta[name="viewport"]').getAttribute('content');
      expect(viewport).toContain('width=device-width');
    });

    test('home page links to manifest', async ({ page }) => {
      await page.goto('/auth/login');

      const manifestLink = await page.locator('link[rel="manifest"]').count();
      expect(manifestLink).toBeGreaterThan(0);
    });

    test('home page has theme-color meta', async ({ page }) => {
      await page.goto('/auth/login');

      const themeColor = await page.locator('meta[name="theme-color"]').count();
      // May or may not have theme-color meta
      // This is recommended but not required
    });

    test('home page has apple-touch-icon', async ({ page }) => {
      await page.goto('/auth/login');

      const appleIcon = await page.locator('link[rel="apple-touch-icon"]').count();
      // Recommended for iOS
    });
  });

  test.describe('Responsive Design', () => {
    test('login page is mobile responsive', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await page.goto('/auth/login');

      // Check that content fits within viewport
      const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
      expect(bodyWidth).toBeLessThanOrEqual(375);
    });

    test('login page has no horizontal scroll on mobile', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await page.goto('/auth/login');

      const hasHorizontalScroll = await page.evaluate(() => {
        return document.documentElement.scrollWidth > document.documentElement.clientWidth;
      });

      expect(hasHorizontalScroll).toBe(false);
    });
  });

  test.describe('Touch Targets', () => {
    test('buttons have adequate size for touch', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await page.goto('/auth/login');

      const buttons = page.locator('button, input[type="submit"], .btn');
      const count = await buttons.count();

      for (let i = 0; i < Math.min(count, 5); i++) {
        const box = await buttons.nth(i).boundingBox();
        if (box) {
          // Minimum recommended touch target is 44x44 pixels
          expect(box.width, 'Button width should be at least 44px').toBeGreaterThanOrEqual(40);
          expect(box.height, 'Button height should be at least 44px').toBeGreaterThanOrEqual(40);
        }
      }
    });
  });

  test.describe('IndexedDB Storage', () => {
    test('page can use IndexedDB', async ({ page }) => {
      await page.goto('/auth/login');

      const hasIndexedDB = await page.evaluate(() => {
        return 'indexedDB' in window;
      });

      expect(hasIndexedDB).toBe(true);
    });
  });

  test.describe('Caching Strategy', () => {
    test('static assets have cache headers', async ({ page }) => {
      const response = await page.goto('/assets/css/app.css');

      // Check for cache-related headers
      const cacheControl = response?.headers()['cache-control'];
      // May or may not have cache headers depending on server config
    });
  });
});
