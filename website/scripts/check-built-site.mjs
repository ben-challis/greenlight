import { readdir, readFile, stat } from 'node:fs/promises';
import { join, relative, resolve } from 'node:path';

const root = resolve('dist');
const base = '/greenlight';
const errors = [];

async function filesBelow(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) {
      files.push(...(await filesBelow(path)));
    } else {
      files.push(path);
    }
  }

  return files;
}

async function exists(path) {
  try {
    return (await stat(path)).isFile();
  } catch {
    return false;
  }
}

function targetFile(pathname) {
  const withoutBase = pathname.slice(base.length).replace(/^\/+/, '');

  if (pathname.endsWith('/')) {
    return join(root, withoutBase, 'index.html');
  }

  return join(root, withoutBase);
}

const files = await filesBelow(root);
const htmlFiles = files.filter((file) => file.endsWith('.html'));
const htmlByFile = new Map();

for (const file of htmlFiles) {
  const html = await readFile(file, 'utf8');
  htmlByFile.set(file, html);

  const label = relative(root, file);

  if (!/<title>[^<]+<\/title>/.test(html)) {
    errors.push(`${label}: missing title`);
  }

  if (!/<meta name="description" content="[^"]+">/.test(html)) {
    errors.push(`${label}: missing description`);
  }

  if (!/<link rel="canonical" href="https:\/\/ben-challis\.github\.io\/greenlight\//.test(html)) {
    errors.push(`${label}: missing or invalid canonical URL`);
  }

  const attributes = html.matchAll(/\b(?:href|src)="([^"]+)"/g);

  for (const [, url] of attributes) {
    if (url.endsWith('.md') && !url.startsWith('https://github.com/')) {
      errors.push(`${label}: source Markdown link leaked into the built site (${url})`);
      continue;
    }

    if (url.startsWith('/') && !url.startsWith(`${base}/`)) {
      errors.push(`${label}: root-relative URL is missing the Pages base path (${url})`);
      continue;
    }

    if (url.startsWith('#')) {
      const id = decodeURIComponent(url.slice(1));

      if (id !== '' && !html.includes(`id="${id}"`)) {
        errors.push(`${label}: missing local fragment target (${url})`);
      }

      continue;
    }

    if (!url.startsWith(`${base}/`)) {
      continue;
    }

    const parsed = new URL(url, 'https://ben-challis.github.io');
    const target = targetFile(parsed.pathname);

    if (!(await exists(target))) {
      errors.push(`${label}: missing internal target (${url})`);
      continue;
    }

    if (parsed.hash !== '' && target.endsWith('.html')) {
      const targetHtml = htmlByFile.get(target) ?? (await readFile(target, 'utf8'));
      const id = decodeURIComponent(parsed.hash.slice(1));

      if (!targetHtml.includes(`id="${id}"`)) {
        errors.push(`${label}: missing cross-page fragment target (${url})`);
      }
    }
  }
}

if (!(await exists(join(root, '_pagefind', 'pagefind.js')))) {
  errors.push('Pagefind search index is missing');
}

if (await exists(join(root, 'docs', 'architecture', 'index.html'))) {
  errors.push('Repository-only architecture documentation was published');
}

if (errors.length > 0) {
  console.error(errors.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`Validated ${htmlFiles.length} HTML pages and their internal targets.`);
}
