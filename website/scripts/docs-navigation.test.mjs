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

async function assertCopyLabel(button, label) {
  await button.page().getByRole('button', { name: label, exact: true }).first().waitFor();
  assert.equal(await button.getAttribute('aria-label'), label);
  assert.equal(await button.getAttribute('title'), label);
}

test('copy controls preserve command text and reset independently after repeated clicks', async (t) => {
  const page = await openDocumentation(t);
  await page.clock.install({ time: new Date('2026-01-01T00:00:00Z') });
  await page.clock.pauseAt(new Date('2026-01-01T00:00:01Z'));
  await page.evaluate(() => {
    window.copiedCommands = [];
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: async (text) => { window.copiedCommands.push(text); } },
    });
    document.execCommand = () => { throw new Error('The modern clipboard must not use the fallback.'); };
  });

  const buttons = page.locator('.docs-command .command-copy');
  const install = buttons.nth(0);
  const directories = buttons.nth(1);
  const idle = 'Copy command to clipboard';
  const copied = 'The clipboard contains the command.';

  await install.click();
  await assertCopyLabel(install, copied);
  assert.equal(await directories.getAttribute('aria-label'), idle);
  await page.clock.runFor(1000);
  await directories.click();
  await assertCopyLabel(directories, copied);
  await page.clock.runFor(1000);
  await install.click();
  await assertCopyLabel(install, copied);

  assert.deepEqual(await page.evaluate(() => window.copiedCommands), [
    'composer require --dev greenlight/greenlight',
    'mkdir -p src tests\ncomposer dump-autoload',
    'composer require --dev greenlight/greenlight',
  ]);

  await page.clock.runFor(1000);
  await assertCopyLabel(install, copied);
  await page.clock.runFor(1000);
  await assertCopyLabel(directories, idle);
  await assertCopyLabel(install, copied);
  await page.clock.runFor(1000);
  await assertCopyLabel(install, idle);
});

for (const clipboard of ['absent', 'denied']) {
  test(`copy controls use the fallback when clipboard access is ${clipboard}`, async (t) => {
    const page = await openDocumentation(t);
    await page.evaluate((mode) => {
      window.clipboardAttempts = 0;
      window.fallbackCopies = [];
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: mode === 'absent' ? undefined : {
          writeText: async () => {
            window.clipboardAttempts += 1;
            throw new DOMException('Clipboard access denied.', 'NotAllowedError');
          },
        },
      });
      document.execCommand = (command) => {
        const input = document.activeElement;
        window.fallbackCopies.push({
          command,
          text: input.value.slice(input.selectionStart, input.selectionEnd),
        });
        return true;
      };
    }, clipboard);

    const button = page.locator('.docs-command .command-copy').first();
    await button.click();
    await assertCopyLabel(button, 'The clipboard contains the command.');

    assert.deepEqual(await page.evaluate(() => window.fallbackCopies), [{
      command: 'copy',
      text: 'composer require --dev greenlight/greenlight',
    }]);
    assert.equal(await page.evaluate(() => window.clipboardAttempts), clipboard === 'absent' ? 0 : 1);
    assert.equal(await page.locator('textarea').count(), 0);
    assert.equal(await button.evaluate((element) => element === document.activeElement), true);
  });
}

test('a failed copy reports the error and permits a successful retry', async (t) => {
  const page = await openDocumentation(t);
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    document.execCommand = () => false;
  });
  const button = page.locator('.docs-command .command-copy').first();
  await button.click();
  await assertCopyLabel(button, 'Copy failed. Try again.');
  assert.equal(await page.locator('textarea').count(), 0);
  assert.equal(await button.evaluate((element) => element === document.activeElement), true);

  await page.evaluate(() => {
    document.execCommand = () => true;
  });
  await button.click();
  await assertCopyLabel(button, 'The clipboard contains the command.');
  assert.equal(await page.locator('textarea').count(), 0);
});

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
