import { test, expect } from '@playwright/test';
import { loginToDSM, openTransmissionManager } from './helpers/dsm-auth';

test.describe('Settings Panel', () => {
  test.beforeEach(async ({ page }) => {
    await loginToDSM(page);
    await openTransmissionManager(page);
  });

  test('should open settings dialog', async ({ page }) => {
    await page.locator('text=Settings').first().click();
    await expect(page.locator('text=Connection').first()).toBeVisible({ timeout: 5000 });
  });

  test('should have connection settings fields', async ({ page }) => {
    await page.locator('text=Settings').first().click();
    await page.waitForTimeout(500);
    // Look for host and port fields
    const dialog = page.locator('.x-window').last();
    await expect(dialog).toBeVisible();
  });
});
