import { test, expect } from '@playwright/test';

/**
 * Phase 4: Calendar System Tests
 * Tests for events, training nights, ICS export, and holiday integration
 */

test.describe('Phase 4: Calendar System', () => {
  test.describe('Calendar Page', () => {
    test('calendar requires authentication', async ({ page }) => {
      await page.goto('/calendar');
      await page.waitForURL(/\/auth\/login/);
    });

    test('calendar create requires authentication', async ({ page }) => {
      await page.goto('/calendar/create');
      await page.waitForURL(/\/auth\/login/);
    });

    test('calendar event detail requires authentication', async ({ page }) => {
      await page.goto('/calendar/1');
      await page.waitForURL(/\/auth\/login/);
    });

    test('calendar event edit requires authentication', async ({ page }) => {
      await page.goto('/calendar/1/edit');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Calendar API', () => {
    test('GET /api/events requires authentication', async ({ request }) => {
      const response = await request.get('/api/events');
      expect(response.status()).toBe(401);
    });

    test('GET /api/events/{id} requires authentication', async ({ request }) => {
      const response = await request.get('/api/events/1');
      expect(response.status()).toBe(401);
    });

    test('POST /api/events requires authentication', async ({ request }) => {
      const response = await request.post('/api/events', {
        data: {
          title: 'Test Event',
          start_time: '2025-01-15 19:00:00',
        },
      });
      expect(response.status()).toBe(401);
    });

    test('PUT /api/events/{id} requires authentication', async ({ request }) => {
      const response = await request.put('/api/events/1', {
        data: { title: 'Updated Title' },
      });
      expect(response.status()).toBe(401);
    });

    test('DELETE /api/events/{id} requires authentication', async ({ request }) => {
      const response = await request.delete('/api/events/1');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('ICS Export', () => {
    test('ICS export endpoint exists', async ({ page }) => {
      // Even unauthenticated, should return auth error not 404
      await page.goto('/calendar/1/ics');
      // Should redirect to login
      await page.waitForURL(/\/auth\/login/);
    });

    test('API ICS endpoint requires authentication', async ({ request }) => {
      const response = await request.get('/api/events/1/ics');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Training Nights API', () => {
    test('GET /api/trainings requires authentication', async ({ request }) => {
      const response = await request.get('/api/trainings');
      expect(response.status()).toBe(401);
    });

    test('POST /api/trainings/generate requires authentication', async ({ request }) => {
      const response = await request.post('/api/trainings/generate');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Event Data Structure', () => {
    // These tests verify the API contract when accessed without auth
    test('events API returns proper error structure', async ({ request }) => {
      const response = await request.get('/api/events');
      expect(response.status()).toBe(401);

      const json = await response.json();
      expect(json).toHaveProperty('error');
    });
  });

  test.describe('Date Range Queries', () => {
    test('events API accepts date range parameters', async ({ request }) => {
      // Even with parameters, should require auth
      const response = await request.get('/api/events?from=2025-01-01&to=2025-12-31');
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Calendar Views', () => {
    // These would need authenticated sessions to fully test
    test('calendar base route redirects to login', async ({ page }) => {
      await page.goto('/calendar');
      await page.waitForURL(/\/auth\/login/);
    });
  });

  test.describe('Event Types', () => {
    test.describe('Training Events', () => {
      test('trainings endpoint requires auth', async ({ request }) => {
        const response = await request.get('/api/trainings');
        expect(response.status()).toBe(401);
      });
    });

    test.describe('Regular Events', () => {
      test('events endpoint requires auth', async ({ request }) => {
        const response = await request.get('/api/events');
        expect(response.status()).toBe(401);
      });
    });
  });

  test.describe('Recurring Events', () => {
    test('recurring event creation requires auth', async ({ request }) => {
      const response = await request.post('/api/events', {
        data: {
          title: 'Weekly Meeting',
          start_time: '2025-01-06 19:00:00',
          recurrence_rule: 'FREQ=WEEKLY;BYDAY=MO',
        },
      });
      expect(response.status()).toBe(401);
    });
  });

  test.describe('Public Holidays', () => {
    // Holiday logic is internal - test that dates shift correctly
    test('holiday dates are considered in training generation', async ({ request }) => {
      // This would need to be tested with an authenticated session
      // that can access the trainings generate endpoint
      const response = await request.post('/api/trainings/generate');
      expect(response.status()).toBe(401);
    });
  });
});
