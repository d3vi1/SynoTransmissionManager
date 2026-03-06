import { Page } from '@playwright/test';

export async function loginToDSM(page: Page): Promise<void> {
  const url = process.env.DSM_URL || 'https://localhost:5001';
  const username = process.env.DSM_USERNAME || '';
  const password = process.env.DSM_PASSWORD || '';

  if (!username || !password) {
    throw new Error('DSM_USERNAME and DSM_PASSWORD environment variables required');
  }

  await page.goto(url);
  await page.waitForSelector('#login_username, input[name="username"]', { timeout: 30000 });

  await page.fill('#login_username, input[name="username"]', username);
  await page.fill('#login_passwd, input[name="passwd"]', password);
  await page.click('#login-btn, button[type="submit"]');

  await page.waitForSelector('.sds-desktop, .syno-sds-desktop', { timeout: 30000 });
}

export async function openTransmissionManager(page: Page): Promise<void> {
  // Open via DSM application launcher
  await page.evaluate(() => {
    if (typeof SYNO !== 'undefined' && SYNO.SDS && SYNO.SDS.AppLaunch) {
      SYNO.SDS.AppLaunch('SYNO.SDS.TransmissionManager');
    }
  });

  await page.waitForSelector('.transmission-manager-window, .syno-sds-appwin', { timeout: 15000 });
}
