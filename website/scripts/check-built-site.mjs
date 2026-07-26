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

const application = await readFile(resolve('../src/Cli/Application.php'), 'utf8');
const configurationReference = await readFile(resolve('../docs/configuration.md'), 'utf8');
const registeredOptions = new Set(
  [...application.matchAll(/new OptionSpec\('([^']+)'/g)].map((match) => match[1]),
);
const documentedOptions = new Set(
  [...configurationReference.matchAll(/^### (?:-[A-Za-z], )?--([a-z][a-z-]*)(?:[=<[]|$)/gm)].map(
    (match) => match[1],
  ),
);

for (const option of registeredOptions) {
  if (!documentedOptions.has(option)) {
    errors.push(`configuration.md: No section exists for CLI option --${option}.`);
  }
}

for (const file of htmlFiles) {
  const html = await readFile(file, 'utf8');
  htmlByFile.set(file, html);

  const label = relative(root, file);

  if (!/<title>[^<]+<\/title>/.test(html)) {
    errors.push(`${label}: The page does not contain a title.`);
  }

  if (!/<meta name="description" content="[^"]+">/.test(html)) {
    errors.push(`${label}: The page does not contain a description.`);
  }

  if (!/<link rel="canonical" href="https:\/\/ben-challis\.github\.io\/greenlight\//.test(html)) {
    errors.push(`${label}: The page does not contain a valid canonical URL.`);
  }

  const attributes = html.matchAll(/\b(?:href|src)="([^"]+)"/g);

  for (const [, url] of attributes) {
    if (url.endsWith('.md') && !url.startsWith('https://github.com/')) {
      errors.push(`${label}: The built site contains a source Markdown link (${url}).`);
      continue;
    }

    if (url.startsWith('/') && !url.startsWith(`${base}/`)) {
      errors.push(`${label}: The root-relative URL does not include the Pages base path (${url}).`);
      continue;
    }

    if (url.startsWith('#')) {
      const id = decodeURIComponent(url.slice(1));

      if (id !== '' && !html.includes(`id="${id}"`)) {
        errors.push(`${label}: Local fragment target does not exist (${url}).`);
      }

      continue;
    }

    if (!url.startsWith(`${base}/`)) {
      continue;
    }

    const parsed = new URL(url, 'https://ben-challis.github.io');
    const target = targetFile(parsed.pathname);

    if (!(await exists(target))) {
      errors.push(`${label}: Internal target does not exist (${url}).`);
      continue;
    }

    if (parsed.hash !== '' && target.endsWith('.html')) {
      const targetHtml = htmlByFile.get(target) ?? (await readFile(target, 'utf8'));
      const id = decodeURIComponent(parsed.hash.slice(1));

      if (!targetHtml.includes(`id="${id}"`)) {
        errors.push(`${label}: Cross-page fragment target does not exist (${url}).`);
      }
    }
  }
}

if (!(await exists(join(root, '_pagefind', 'pagefind.js')))) {
  errors.push('The Pagefind search index does not exist.');
}

if (await exists(join(root, 'docs', 'architecture', 'index.html'))) {
  errors.push('The site contains repository-only architecture documentation.');
}

if (errors.length > 0) {
  console.error(errors.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`The site check validated ${htmlFiles.length} HTML pages and their internal targets.`);
}
