import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { preview } from 'astro';
import { chromium } from 'playwright';

let server;
let browser;

before(async () => {
  server = await preview({ server: { host: '127.0.0.1', port: 0 }, logLevel: 'silent' });
  browser = await chromium.launch();
});

after(async () => {
  await browser?.close();
  await server?.stop();
});

async function openDocumentation(t) {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  t.after(() => page.close());
  await page.goto(`http://127.0.0.1:${server.port}/greenlight/docs/getting-started/`);
  await page.addStyleTag({ content: 'html { scroll-behavior: auto !important }' });
  return page;
}

test('page-index navigation preserves the keyboard position in the selected section', async (t) => {
  const page = await openDocumentation(t);
  await page.locator('.mobile-index-trigger').click();
  await page.locator('#mobile-page-index a[href="#exit-codes"]').focus();
  await page.keyboard.press('Enter');
  await page.waitForURL('**/#exit-codes');
  await page.waitForFunction(() => !document.querySelector('#mobile-page-index').open);
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  await page.keyboard.press('Tab');

  assert.equal(
    await page.evaluate(() => document.activeElement.getAttribute('href')),
    '/greenlight/docs/configuration/#interruption',
  );
});

for (const route of ['getting-started', 'api-expectations', 'phpstan', 'migrating-from-phpunit']) {
  test(`${route} keeps prose within a narrow viewport`, async (t) => {
    const page = await openDocumentation(t);
    await page.setViewportSize({ width: 320, height: 844 });
    await page.goto(`http://127.0.0.1:${server.port}/greenlight/docs/${route}/`);
    await page.evaluate(() => document.fonts.ready);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    assert.equal(await page.locator('.docs-article pre').first().evaluate((element) => {
      const style = getComputedStyle(element);
      return style.whiteSpace === 'pre' && style.overflowX === 'auto';
    }), true);
  });
}

for (const [trigger, dialog] of [
  ['.mobile-doc-trigger', '#mobile-documentation-menu'],
  ['.mobile-index-trigger', '#mobile-page-index'],
]) {
  for (const dismissal of ['Escape', 'Close']) {
    test(`${dialog} restores trigger focus after ${dismissal}`, async (t) => {
      const page = await openDocumentation(t);
      await page.locator(trigger).click();

      if (dismissal === 'Escape') {
        await page.keyboard.press('Escape');
      } else {
        await page.locator(`${dialog} button[type="submit"]`).click();
      }

      await page.waitForFunction((selector) => !document.querySelector(selector).open, dialog);
      assert.equal(await page.locator(trigger).evaluate((element) => element === document.activeElement), true);
    });
  }
}
