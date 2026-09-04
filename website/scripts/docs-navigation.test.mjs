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

test('keyboard focus has sufficient contrast on documentation controls', async (t) => {
  const page = await openDocumentation(t);
  await page.keyboard.press('Tab');

  const checkFocus = async (selector) => {
    const control = page.locator(selector).first();
    await control.focus();
    const contrast = await control.evaluate((element) => {
      const style = getComputedStyle(element);
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1;
      const context = canvas.getContext('2d');
      const luminance = (color) => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        const channels = [...context.getImageData(0, 0, 1, 1).data].slice(0, 3).map((value) => {
          const channel = value / 255;
          return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        });
        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
      };
      const outline = luminance(style.outlineColor);
      const backgrounds = ['--canvas', '--surface', '--code-surface', '--signal-soft'].map((token) =>
        luminance(style.getPropertyValue(token)),
      );
      return {
        visible: element.matches(':focus-visible') && style.outlineStyle !== 'none',
        ratio: Math.min(...backgrounds.map((background) =>
          (Math.max(outline, background) + 0.05) / (Math.min(outline, background) + 0.05),
        )),
      };
    });
    assert.equal(contrast.visible, true, `${selector} has a visible focus indicator.`);
    assert.ok(contrast.ratio >= 3, `${selector} has a contrast ratio of ${contrast.ratio.toFixed(2)}.`);
  };

  await checkFocus('.mobile-doc-trigger');
  await checkFocus('.command-copy');
  await checkFocus('.site-search summary');
  await page.keyboard.press('Enter');
  await page.locator('#pagefind-search input').waitFor();
  await checkFocus('#pagefind-search input');
  await page.locator('#pagefind-search input').fill('test');
  await checkFocus('.pagefind-ui__search-clear');
});

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
