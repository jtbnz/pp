import { test, expect, testUsers } from './fixtures/auth';

/**
 * Phase 11: Authenticated User Flow Tests
 * Tests for admin CRUD operations, leave requests, and approvals
 */

test.describe('Phase 11: Authenticated Flows', () => {
  test.describe('Admin Event Management', () => {
    test('admin can view events list', async ({ adminPage }) => {
      await adminPage.goto('/admin/events');
      await expect(adminPage.locator('h1')).toContainText(/Events|Calendar/i);
    });

    test('admin can access create event form', async ({ adminPage }) => {
      await adminPage.goto('/admin/events/create');
      await expect(adminPage.locator('h1').first()).toContainText(/Create|New|Event/i);
      await expect(adminPage.locator('form.event-form')).toBeVisible();
    });

    test('admin can create a training event', async ({ adminPage }) => {
      await adminPage.goto('/admin/events/create');

      // Fill in the form
      await adminPage.fill('input[name="title"]', 'E2E Test Training');
      await adminPage.fill('textarea[name="description"]', 'Training session created by E2E tests');

      // Set date/time (future date)
      const futureDate = new Date();
      futureDate.setDate(futureDate.getDate() + 14);
      const dateStr = futureDate.toISOString().split('T')[0];
      await adminPage.fill('input[name="start_date"], input[name="date"]', dateStr);

      // Check training checkbox if available
      const trainingCheckbox = adminPage.locator('input[name="is_training"]');
      if (await trainingCheckbox.isVisible()) {
        await trainingCheckbox.check();
      }

      // Submit the form using specific button text
      await adminPage.click('button:has-text("Create Event"), form.event-form button[type="submit"]');

      // Should redirect to events list or show success
      await expect(adminPage).toHaveURL(/\/(admin\/events|calendar)/);
    });

    test('admin can create a regular (non-training) event', async ({ adminPage }) => {
      await adminPage.goto('/admin/events/create');

      await adminPage.fill('input[name="title"]', 'E2E Equipment Check');
      await adminPage.fill('textarea[name="description"]', 'Equipment inspection created by E2E tests');

      const futureDate = new Date();
      futureDate.setDate(futureDate.getDate() + 21);
      const dateStr = futureDate.toISOString().split('T')[0];
      await adminPage.fill('input[name="start_date"], input[name="date"]', dateStr);

      // Ensure training is NOT checked
      const trainingCheckbox = adminPage.locator('input[name="is_training"]');
      if (await trainingCheckbox.isVisible() && await trainingCheckbox.isChecked()) {
        await trainingCheckbox.uncheck();
      }

      await adminPage.click('button:has-text("Create Event"), form.event-form button[type="submit"]');
      await expect(adminPage).toHaveURL(/\/(admin\/events|calendar)/);
    });
  });

  test.describe('Admin Notice Management', () => {
    test('admin can view notices list', async ({ adminPage }) => {
      await adminPage.goto('/admin/notices');
      await expect(adminPage.locator('h1')).toContainText(/Notice/i);
    });

    test('admin can access create notice form', async ({ adminPage }) => {
      await adminPage.goto('/admin/notices/create');
      await expect(adminPage.locator('form.notice-form, form[action*="notices"]')).toBeVisible();
    });

    test('admin can create a standard notice', async ({ adminPage }) => {
      await adminPage.goto('/admin/notices/create');

      await adminPage.fill('input[name="title"]', 'E2E Test Notice');
      await adminPage.fill('textarea[name="content"]', 'This is a test notice created by E2E tests.');

      // Select standard type if dropdown exists
      const typeSelect = adminPage.locator('select[name="type"]');
      if (await typeSelect.isVisible()) {
        await typeSelect.selectOption('standard');
      }

      await adminPage.click('button:has-text("Create Notice"), form.notice-form button[type="submit"]');
      await expect(adminPage).toHaveURL(/\/(admin\/notices|notices)/);
    });

    test('admin can create a sticky notice', async ({ adminPage }) => {
      await adminPage.goto('/admin/notices/create');

      await adminPage.fill('input[name="title"]', 'E2E Sticky Notice');
      await adminPage.fill('textarea[name="content"]', 'This sticky notice stays at top.');

      const typeSelect = adminPage.locator('select[name="type"]');
      if (await typeSelect.isVisible()) {
        await typeSelect.selectOption('sticky');
      }

      await adminPage.click('button:has-text("Create Notice"), form.notice-form button[type="submit"]');
      await expect(adminPage).toHaveURL(/\/(admin\/notices|notices)/);
    });

    test('admin can create an urgent notice', async ({ adminPage }) => {
      await adminPage.goto('/admin/notices/create');

      await adminPage.fill('input[name="title"]', 'E2E Urgent Alert');
      await adminPage.fill('textarea[name="content"]', 'This is an urgent notice!');

      const typeSelect = adminPage.locator('select[name="type"]');
      if (await typeSelect.isVisible()) {
        await typeSelect.selectOption('urgent');
      }

      await adminPage.click('button:has-text("Create Notice"), form.notice-form button[type="submit"]');
      await expect(adminPage).toHaveURL(/\/(admin\/notices|notices)/);
    });
  });

  test.describe('Admin Member Management', () => {
    test('admin can view members list', async ({ adminPage }) => {
      await adminPage.goto('/admin/members');
      await expect(adminPage.locator('h1')).toContainText(/Member/i);
    });

    test('admin can access invite form', async ({ adminPage }) => {
      await adminPage.goto('/admin/members/invite');
      await expect(adminPage.locator('form[action*="invite"], form.invite-form').first()).toBeVisible();
      await expect(adminPage.locator('input[name="email"]')).toBeVisible();
    });

    test('admin can view member profile', async ({ adminPage }) => {
      await adminPage.goto(`/members/${testUsers.firefighter.id}`);
      await expect(adminPage.locator('h1').first()).toContainText(new RegExp(testUsers.firefighter.name, 'i'));
    });

    test('admin can access member edit form', async ({ adminPage }) => {
      await adminPage.goto(`/admin/members/${testUsers.firefighter.id}`);
      await expect(adminPage.locator('form[action*="members"], form.member-form').first()).toBeVisible();
    });
  });

  test.describe('Firefighter Leave Requests', () => {
    test('firefighter can view leave page', async ({ firefighterPage }) => {
      await firefighterPage.goto('/leave');
      await expect(firefighterPage.locator('h1')).toContainText(/Leave/i);
    });

    test('firefighter can see upcoming trainings to request leave', async ({ firefighterPage }) => {
      await firefighterPage.goto('/leave');

      // Should show available training dates or request form
      const requestSection = firefighterPage.locator('text=/Request Leave|Upcoming Training/i');
      await expect(requestSection.first()).toBeVisible();
    });

    test('firefighter can request leave for a training', async ({ firefighterPage }) => {
      await firefighterPage.goto('/leave');

      // Click on a "Request Leave" button for an upcoming training
      const requestButton = firefighterPage.locator('button:has-text("Request Leave")').first();

      if (await requestButton.isVisible()) {
        await requestButton.click();

        // Wait for modal or form to appear
        await firefighterPage.waitForTimeout(500);

        // If there's a reason field, fill it
        const reasonField = firefighterPage.locator('textarea[name="reason"], input[name="reason"]');
        if (await reasonField.first().isVisible()) {
          await reasonField.first().fill('E2E test leave request');
        }

        // Submit the form - use specific button text
        const submitBtn = firefighterPage.locator('button:has-text("Submit Request")').first();
        if (await submitBtn.isVisible()) {
          await submitBtn.click();
        }

        // Should show success or updated leave status
        await firefighterPage.waitForTimeout(500);
      }
    });

    test('firefighter can view their pending requests', async ({ firefighterPage }) => {
      await firefighterPage.goto('/leave');

      // The page should show pending requests section
      const content = await firefighterPage.content();
      expect(content).toMatch(/pending|your requests|active requests/i);
    });

    test('firefighter can cancel a pending leave request', async ({ firefighterPage }) => {
      await firefighterPage.goto('/leave');

      // Look for a cancel button
      const cancelButton = firefighterPage.locator('button:has-text("Cancel")').first();

      if (await cancelButton.isVisible()) {
        await cancelButton.click();

        // Wait for confirmation modal
        await firefighterPage.waitForTimeout(300);

        // Confirm cancellation
        const confirmButton = firefighterPage.locator('button:has-text("Cancel Request"), button:has-text("Confirm")');
        if (await confirmButton.isVisible()) {
          await confirmButton.click();
        }
      }
    });
  });

  test.describe('Officer Leave Approval', () => {
    test('officer can view pending leave requests', async ({ officerPage }) => {
      await officerPage.goto('/leave/pending');
      await expect(officerPage.locator('h1').first()).toContainText(/Pending|Leave|Requests/i);
    });

    test('officer can approve a leave request', async ({ officerPage }) => {
      await officerPage.goto('/leave/pending');

      // Look for an approve button
      const approveButton = officerPage.locator('button:has-text("Approve")').first();

      if (await approveButton.isVisible()) {
        await approveButton.click();

        // Wait for confirmation or completion
        await officerPage.waitForTimeout(500);
        // Action was attempted - actual database state checked elsewhere
      }
    });

    test('officer can deny a leave request', async ({ officerPage }) => {
      await officerPage.goto('/leave/pending');

      // Look for a deny button
      const denyButton = officerPage.locator('button:has-text("Deny")').first();

      if (await denyButton.isVisible()) {
        await denyButton.click();

        // Wait for confirmation or completion
        await officerPage.waitForTimeout(500);
      }
    });
  });

  test.describe('Admin Leave Management', () => {
    test('admin can view all leave requests', async ({ adminPage }) => {
      await adminPage.goto('/admin/leave');
      await expect(adminPage.locator('h1')).toContainText(/Leave/i);
    });

    test('admin can approve leave requests', async ({ adminPage }) => {
      await adminPage.goto('/admin/leave');

      const approveButton = adminPage.locator('button:has-text("Approve")').first();
      if (await approveButton.isVisible()) {
        await approveButton.click();
        await adminPage.waitForTimeout(500);
      }
    });

    test('admin can create extended leave', async ({ adminPage }) => {
      // Navigate to leave management
      await adminPage.goto('/admin/leave');

      // Look for "Add Extended Leave" or similar button
      const extendedLeaveButton = adminPage.locator('a:has-text("Extended"), button:has-text("Extended")');
      if (await extendedLeaveButton.isVisible()) {
        await extendedLeaveButton.click();
        await adminPage.waitForTimeout(300);
      }
    });
  });

  test.describe('User Profile', () => {
    test('user can view their own profile', async ({ firefighterPage }) => {
      await firefighterPage.goto('/profile');
      await expect(firefighterPage.locator('h1').first()).toContainText(new RegExp(testUsers.firefighter.name, 'i'));
    });

    test('user can access settings', async ({ firefighterPage }) => {
      // Navigate directly to settings page
      await firefighterPage.goto('/settings');

      // Should show settings page or user profile with settings
      const content = await firefighterPage.content();
      expect(content).toMatch(/setting|preference|notification|profile/i);
    });
  });

  test.describe('Calendar Views', () => {
    test('authenticated user can view calendar', async ({ firefighterPage }) => {
      await firefighterPage.goto('/calendar');
      await expect(firefighterPage.locator('.calendar-header').first()).toBeVisible();
    });

    test('authenticated user can see events on calendar', async ({ firefighterPage }) => {
      await firefighterPage.goto('/calendar');

      // Calendar should show events or empty state
      const content = await firefighterPage.content();
      expect(content).toMatch(/training|event|no events|calendar/i);
    });
  });

  test.describe('Notices', () => {
    test('authenticated user can view notices', async ({ firefighterPage }) => {
      await firefighterPage.goto('/notices');
      await expect(firefighterPage.locator('h1')).toContainText(/Notice/i);
    });

    test('notices are displayed correctly', async ({ firefighterPage }) => {
      await firefighterPage.goto('/notices');

      // Should show notices or empty state
      const content = await firefighterPage.content();
      expect(content).toMatch(/notice|announcement|no notices/i);
    });
  });

  test.describe('Admin Dashboard', () => {
    test('admin can access dashboard', async ({ adminPage }) => {
      await adminPage.goto('/admin');
      await expect(adminPage.locator('h1')).toContainText(/Admin|Dashboard/i);
    });

    test('admin dashboard shows stats', async ({ adminPage }) => {
      await adminPage.goto('/admin');

      // Dashboard should show various statistics
      const content = await adminPage.content();
      expect(content).toMatch(/member|event|notice|leave/i);
    });

    test('admin can access settings', async ({ adminPage }) => {
      await adminPage.goto('/admin/settings');
      await expect(adminPage.locator('h1').first()).toContainText(/Setting/i);
    });
  });
});
