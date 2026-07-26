const copyResetTimers = new WeakMap<HTMLButtonElement, number>();
const svgNamespace = 'http://www.w3.org/2000/svg';

type CopyState = 'idle' | 'copied' | 'error';

function copyIcon(state: CopyState): SVGSVGElement {
  const icon = document.createElementNS(svgNamespace, 'svg');
  icon.setAttribute('aria-hidden', 'true');
  icon.setAttribute('focusable', 'false');
  icon.setAttribute('viewBox', '0 0 24 24');

  if (state === 'copied') {
    const check = document.createElementNS(svgNamespace, 'path');
    check.setAttribute('d', 'm5 12 4 4L19 6');
    icon.append(check);
    return icon;
  }

  const clipboard = document.createElementNS(svgNamespace, 'path');
  clipboard.setAttribute(
    'd',
    'M9 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3M9 3h6v4H9z',
  );
  icon.append(clipboard);
  return icon;
}

function setCopyState(button: HTMLButtonElement, state: CopyState): void {
  const labels = {
    idle: 'Copy command to clipboard',
    copied: 'Command copied to clipboard',
    error: 'Copy failed, try again',
  };

  button.replaceChildren(copyIcon(state));
  button.setAttribute('aria-label', labels[state]);
  button.title = labels[state];

  if (state === 'idle') {
    delete button.dataset.state;
  } else {
    button.dataset.state = state;
  }
}

async function writeToClipboard(text: string): Promise<void> {
  if (navigator.clipboard) {
    try {
      await navigator.clipboard.writeText(text);
      return;
    } catch {
      // Fall back for browsers that expose the API but deny clipboard access.
    }
  }

  const activeElement =
    document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const input = document.createElement('textarea');
  input.value = text;
  input.setAttribute('readonly', '');
  input.style.position = 'fixed';
  input.style.opacity = '0';
  document.body.append(input);
  input.select();

  const clipboardDocument = document as unknown as {
    execCommand(command: string): boolean;
  };
  const copied = clipboardDocument.execCommand('copy');

  input.remove();
  activeElement?.focus();

  if (!copied) {
    throw new Error('Unable to copy command');
  }
}

function wrapDocumentationCommands(): void {
  for (const codeBlock of document.querySelectorAll<HTMLPreElement>(
    '.docs-article pre[data-language="sh"], .docs-article pre[data-language="bash"], .docs-article pre[data-language="shell"]',
  )) {
    if (codeBlock.closest('.docs-command')) {
      continue;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'docs-command';
    wrapper.dataset.copyCommand = '';
    codeBlock.before(wrapper);
    wrapper.append(codeBlock);
  }
}

function enhanceCopyCommand(container: HTMLElement): void {
  if (container.dataset.copyReady !== undefined) {
    return;
  }

  const command = container.querySelector('code')?.textContent?.trim() ?? '';

  if (command === '') {
    return;
  }

  container.dataset.copyReady = '';

  const button = document.createElement('button');
  button.className = 'command-copy';
  button.type = 'button';
  button.setAttribute('aria-live', 'polite');
  setCopyState(button, 'idle');

  button.addEventListener('click', async () => {
    const existingTimer = copyResetTimers.get(button);

    if (existingTimer !== undefined) {
      window.clearTimeout(existingTimer);
    }

    try {
      await writeToClipboard(command);
      setCopyState(button, 'copied');
    } catch {
      setCopyState(button, 'error');
    }

    copyResetTimers.set(
      button,
      window.setTimeout(() => {
        setCopyState(button, 'idle');
        copyResetTimers.delete(button);
      }, 3000),
    );
  });

  container.append(button);
}

export function initializeCopyCommands(): void {
  wrapDocumentationCommands();

  for (const container of document.querySelectorAll<HTMLElement>('[data-copy-command]')) {
    enhanceCopyCommand(container);
  }
}
