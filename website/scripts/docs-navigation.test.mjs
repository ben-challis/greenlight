import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { setTimeout } from 'node:timers/promises';
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

test('wide tables retain their semantics and support keyboard scrolling without scripts', async (t) => {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
  t.after(() => page.close());
  await page.goto(`http://127.0.0.1:${server.port}/greenlight/docs/migrating-from-phpunit/`);
  const table = page.getByRole('table').first();
  const region = page.getByRole('region', { name: 'Convert tests automatically table', exact: true });
  assert.equal(await region.getByRole('table').count(), 1);
  assert.ok(await table.getByRole('columnheader').count() > 0);
  assert.equal(await region.getAttribute('tabindex'), '0');
  assert.equal(await region.evaluate((element) => element.scrollWidth > element.clientWidth), true);
  await region.scrollIntoViewIfNeeded();
  await region.focus();
  await page.keyboard.press('ArrowRight');
  for (let attempt = 0; attempt < 20 && await region.evaluate((element) => element.scrollLeft === 0); attempt++) {
    await setTimeout(50);
  }
  assert.ok(await region.evaluate((element) => element.scrollLeft) > 0);
  assert.equal(await region.evaluate((element) => element === document.activeElement), true);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
});
