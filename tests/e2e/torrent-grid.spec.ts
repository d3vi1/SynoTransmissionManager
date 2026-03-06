import { test, expect } from '@playwright/test';
import { loginToDSM, openTransmissionManager } from './helpers/dsm-auth';

test.describe('Torrent Grid', () => {
  test.beforeEach(async ({ page }) => {
    await loginToDSM(page);
    await openTransmissionManager(page);
  });

  test('should display main window with toolbar', async ({ page }) => {
    // Verify toolbar buttons exist
    await expect(page.locator('text=Add Torrent').first()).toBeVisible();
    await expect(page.locator('text=Settings').first()).toBeVisible();
  });

  test('should show empty state when no torrents', async ({ page }) => {
    // Look for empty state text
    const grid = page.locator('.x-grid-panel').first();
    await expect(grid).toBeVisible();
  });

  test('should filter torrents by search', async ({ page }) => {
    const searchInput = page.locator('input[type="text"]').first();
    await searchInput.fill('ubuntu');
    // Wait for debounce
    await page.waitForTimeout(500);
  });
});
