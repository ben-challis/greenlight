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

for (const route of ['attributes', 'phpstan', 'migrating-from-phpunit']) {
  test(`${route} prints complete content without navigation or controls`, async (t) => {
    const page = await browser.newPage({ viewport: { width: 794, height: 1123 } });
    t.after(() => page.close());
    await page.goto(`http://127.0.0.1:${server.port}/greenlight/docs/${route}/`);
    await page.locator('.mobile-doc-trigger').click();
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => document.fonts.ready);

    for (const selector of ['.site-header', '.docs-sidebar', '.page-index', '.mobile-doc-toolbar',
      '#mobile-documentation-menu', '.doc-pagination', '.command-copy']) {
      assert.equal(await page.locator(selector).first().isVisible(), false, `${selector} is absent from print output.`);
    }

    assert.equal(await page.locator('.docs-article').isVisible(), true);
    assert.equal(await page.evaluate(() => getComputedStyle(document.body).overflow), 'visible');
    assert.deepEqual(await page.locator('.docs-article pre, .docs-article table').evaluateAll((elements) =>
      elements.filter((element) => element.scrollWidth > element.clientWidth + 1).map((element) => element.tagName),
    ), []);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);

    await page.emulateMedia({ media: 'screen' });
    assert.equal(await page.locator('#mobile-documentation-menu').isVisible(), true);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('.site-header').isVisible(), true);
  });
}
