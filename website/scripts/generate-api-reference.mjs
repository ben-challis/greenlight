import { readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptDirectory, '../..');
const sourceRoot = resolve(repositoryRoot, 'src');
const documentationRoot = resolve(repositoryRoot, 'docs');

const sections = [
  {
    id: 'api-attributes',
    title: 'Attributes and conditions API',
    description: 'This reference lists the attributes and conditions that control test discovery and execution.',
    prefixes: ['Greenlight\\Attribute\\', 'Greenlight\\Condition\\'],
  },
  {
    id: 'api-configuration',
    title: 'Configuration API',
    description: 'This reference lists the builders that configure Greenlight runs.',
    prefixes: ['Greenlight\\Config\\'],
  },
  {
    id: 'api-artifacts',
    title: 'Artifact API',
    description: 'This reference lists attachment values, retention rules, and attachment operations.',
    prefixes: ['Greenlight\\Core\\Artifact\\'],
  },
  {
    id: 'api-events',
    title: 'Event API',
    description: 'This reference lists the events that plugins and reporters receive during a run.',
    prefixes: ['Greenlight\\Core\\Event\\'],
  },
  {
    id: 'api-results',
    title: 'Result API',
    description: 'This reference lists test outcomes, diagnostics, failure details, and result values.',
    prefixes: ['Greenlight\\Core\\Result\\'],
  },
  {
    id: 'api-test-contracts',
    title: 'Test contracts API',
    description: 'This reference lists test metadata, skip signals, conditions, and wire contracts.',
    prefixes: ['Greenlight\\Core\\Test\\', 'Greenlight\\Core\\Wire\\'],
    names: ['Greenlight\\Core\\AtomicFileError', 'Greenlight\\Core\\Condition'],
  },
  {
    id: 'api-expectations',
    title: 'Expectations API',
    description: 'This reference lists immediate and temporal expectation types.',
    prefixes: ['Greenlight\\Expect\\'],
  },
  {
    id: 'api-doubles',
    title: 'Doubles API',
    description: 'This reference lists double factories, argument matchers, captors, and mock plans.',
    prefixes: ['Greenlight\\Doubles\\'],
  },
  {
    id: 'api-fixtures-harness',
    title: 'Fixtures and harness API',
    description: 'This reference lists fixtures and harness service contracts.',
    prefixes: ['Greenlight\\Fixture\\', 'Greenlight\\Harness\\'],
  },
  {
    id: 'api-plugins',
    title: 'Plugin API',
    description: 'This reference lists plugin capabilities and lifecycle callback contracts.',
    prefixes: ['Greenlight\\Plugin\\'],
  },
  {
    id: 'api-reporting',
    title: 'Reporter API',
    description: 'This reference lists reporter and output contracts.',
    prefixes: ['Greenlight\\Reporting\\'],
  },
  {
    id: 'api-integrations',
    title: 'Integration API',
    description: 'This reference lists public integration types for Laravel, Rector, and Symfony.',
    prefixes: ['Greenlight\\Laravel\\', 'Greenlight\\PhpStan\\', 'Greenlight\\Rector\\', 'Greenlight\\Symfony\\'],
  },
];

const check = process.argv.includes('--check');

const sourceFiles = await filesBelow(sourceRoot);
const publicTypes = [];

for (const file of sourceFiles.filter((path) => path.endsWith('.php'))) {
  const source = await readFile(file, 'utf8');
  const sourceFile = relative(repositoryRoot, file);
  let type;

  try {
    type = parseType(source, sourceFile);
  } catch (error) {
    throw new Error(`${sourceFile}: ${error.message}`, { cause: error });
  }

  if (type !== undefined && !type.internal) {
    publicTypes.push(type);
  }
}

publicTypes.sort((left, right) => left.name.localeCompare(right.name));

const unformattedIdentifiers = [];

for (const type of publicTypes) {
  collectUnformattedIdentifiers(unformattedIdentifiers, type.name, type.doc.description);

  for (const member of type.members) {
    collectUnformattedIdentifiers(unformattedIdentifiers, `${type.name}::${member.name}`, member.doc.description);
  }
}

if (unformattedIdentifiers.length > 0) {
  throw new Error(`Public API PHPDoc contains unformatted code identifiers: ${unformattedIdentifiers.join(', ')}.`);
}

const assigned = new Set();
const generated = new Map();

for (const section of sections) {
  const types = publicTypes.filter((type) => {
    const matchesPrefix = section.prefixes.some((prefix) => type.name.startsWith(prefix));
    const matchesName = section.names?.includes(type.name) ?? false;

    return matchesPrefix || matchesName;
  });
  const shortNameCounts = new Map();

  for (const type of types) {
    if (assigned.has(type.name)) {
      throw new Error(`Public API type "${type.name}" belongs to more than one section.`);
    }

    assigned.add(type.name);
    shortNameCounts.set(type.shortName, (shortNameCounts.get(type.shortName) ?? 0) + 1);
  }

  // Qualify colliding short names so every heading and anchor stays unique,
  // for example the Laravel and Symfony Service attributes.
  for (const type of types) {
    type.headingName =
      (shortNameCounts.get(type.shortName) ?? 0) > 1
        ? type.name.split('\\').slice(-2).join('\\')
        : type.shortName;
  }

  generated.set(`${section.id}.md`, renderSection(section, types));
}

const unassigned = publicTypes.filter((type) => !assigned.has(type.name));

if (unassigned.length > 0) {
  throw new Error(`No API section contains these public types: ${unassigned.map((type) => type.name).join(', ')}.`);
}

generated.set('api.md', renderIndex());

const errors = [];

for (const [name, content] of generated) {
  const path = resolve(documentationRoot, name);

  if (check) {
    let current;

    try {
      current = await readFile(path, 'utf8');
    } catch {
      errors.push(`${name}: The generated API reference file does not exist.`);
      continue;
    }

    if (current !== content) {
      errors.push(`${name}: The generated API reference is not current.`);
    }

    continue;
  }

  await writeFile(path, content);
}

if (errors.length > 0) {
  console.error(errors.join('\n'));
  console.error('Run "npm --prefix website run api:generate" to update the API reference.');
  process.exitCode = 1;
} else if (check) {
  console.log(`The API reference contains ${publicTypes.length} public types in ${sections.length} sections.`);
} else {
  console.log(`Generated API reference for ${publicTypes.length} public types in ${sections.length} sections.`);
}

async function filesBelow(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = resolve(directory, entry.name);

    if (entry.isDirectory()) {
      files.push(...(await filesBelow(path)));
    } else {
      files.push(path);
    }
  }

  return files;
}

function parseType(source, file) {
  const tokens = tokenize(source);
  const namespace = namespaceName(tokens);
  let depth = 0;
  let declarationIndex = -1;

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token.value === '{') {
      depth += 1;
      continue;
    }

    if (token.value === '}') {
      depth -= 1;
      continue;
    }

    if (
      depth === 0
      && token.kind === 'word'
      && ['class', 'interface', 'enum', 'trait'].includes(token.value)
      && tokens[index - 1]?.value !== '::'
    ) {
      declarationIndex = index;
      break;
    }
  }

  if (declarationIndex === -1) {
    return undefined;
  }

  const kind = tokens[declarationIndex].value;
  const nameToken = tokens.slice(declarationIndex + 1).find((token) => token.kind === 'word');

  if (nameToken === undefined) {
    throw new Error(`${file}: The API parser cannot find the type name.`);
  }

  const openIndex = tokens.findIndex((token, index) => index > declarationIndex && token.value === '{');

  if (openIndex === -1) {
    throw new Error(`${file}: The API parser cannot find the type body.`);
  }

  const closeIndex = matchingBrace(tokens, openIndex);
  const boundaryIndex = previousTopLevelBoundary(tokens, declarationIndex);
  const declarationTokens = tokens.slice(boundaryIndex + 1, openIndex);
  const typeDoc = [...declarationTokens].reverse().find((token) => token.kind === 'doc');
  const signatureStart = firstSignatureToken(declarationTokens);
  const signature = source.slice(signatureStart.start, tokens[openIndex].start).trim();
  const members = parseMembers(source, tokens, openIndex, closeIndex, kind);
  const shortName = nameToken.value;

  return {
    name: namespace === '' ? shortName : `${namespace}\\${shortName}`,
    shortName,
    kind,
    file,
    line: lineAt(source, tokens[declarationIndex].start),
    doc: parseDoc(typeDoc?.value),
    internal: typeDoc?.value.includes('@internal') ?? false,
    signature,
    members,
  };
}

function namespaceName(tokens) {
  const namespaceIndex = tokens.findIndex((token) => token.value === 'namespace');

  if (namespaceIndex === -1) {
    return '';
  }

  const parts = [];

  for (let index = namespaceIndex + 1; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token.value === ';' || token.value === '{') {
      break;
    }

    if (token.kind === 'word' || token.value === '\\') {
      parts.push(token.value);
    }
  }

  return parts.join('');
}

function previousTopLevelBoundary(tokens, declarationIndex) {
  let depth = 0;

  for (let index = declarationIndex - 1; index >= 0; index -= 1) {
    const token = tokens[index];

    if (token.value === '}') {
      depth += 1;
    } else if (token.value === '{') {
      depth -= 1;
    }

    if (depth === 0 && (token.value === ';' || token.value === '}')) {
      return index;
    }
  }

  return -1;
}

function firstSignatureToken(tokens) {
  let index = 0;

  while (index < tokens.length && tokens[index].kind === 'doc') {
    index += 1;
  }

  if (tokens[index] === undefined) {
    throw new Error('The API parser cannot find the declaration signature.');
  }

  return tokens[index];
}

function parseMembers(source, tokens, openIndex, closeIndex, typeKind) {
  const members = [];
  let memberStart = openIndex + 1;
  let index = openIndex + 1;

  while (index < closeIndex) {
    const token = tokens[index];

    if (token.value === '{') {
      const significant = memberTokens(tokens.slice(memberStart, index));
      const endIndex = matchingBrace(tokens, index);

      if (isMethod(significant)) {
        addMember(members, source, significant, token.start, 'method', typeKind);
      } else if (isPublicProperty(significant)) {
        const propertyTokens = [...significant, ...tokens.slice(index, endIndex + 1)];
        addMember(members, source, propertyTokens, tokens[endIndex].end, 'property', typeKind);
      }

      memberStart = endIndex + 1;
      index = endIndex + 1;
      continue;
    }

    if (token.value === ';') {
      const significant = memberTokens(tokens.slice(memberStart, index));
      const memberKind = classifySemicolonMember(significant);

      if (memberKind !== undefined) {
        addMember(members, source, significant, token.end, memberKind, typeKind);
      }

      memberStart = index + 1;
    }

    index += 1;
  }

  return members;
}

function memberTokens(tokens) {
  let index = 0;

  while (index < tokens.length && tokens[index].kind === 'doc') {
    index += 1;
  }

  while (tokens[index]?.value === '#' && tokens[index + 1]?.value === '[') {
    let attributeDepth = 0;

    do {
      if (tokens[index].value === '[') {
        attributeDepth += 1;
      } else if (tokens[index].value === ']') {
        attributeDepth -= 1;
      }

      index += 1;
    } while (index < tokens.length && attributeDepth > 0);
  }

  return tokens.slice(0, index).filter((token) => token.kind === 'doc').concat(tokens.slice(index));
}

function matchingBrace(tokens, openIndex) {
  let depth = 0;

  for (let index = openIndex; index < tokens.length; index += 1) {
    if (tokens[index].value === '{') {
      depth += 1;
    } else if (tokens[index].value === '}') {
      depth -= 1;

      if (depth === 0) {
        return index;
      }
    }
  }

  throw new Error('The API parser found an unmatched brace.');
}

function isMethod(tokens) {
  return tokens.some((token) => token.value === 'function');
}

function isPublicProperty(tokens) {
  return isPublic(tokens, 'class') && tokens.some((token) => token.kind === 'variable');
}

function classifySemicolonMember(tokens) {
  if (tokens.length === 0) {
    return undefined;
  }

  if (tokens.some((token) => token.value === 'function')) {
    return 'method';
  }

  if (tokens.some((token) => token.value === 'case')) {
    return 'case';
  }

  if (tokens.some((token) => token.value === 'const')) {
    return 'constant';
  }

  if (tokens.some((token) => token.kind === 'variable')) {
    return 'property';
  }

  return undefined;
}

function addMember(members, source, tokens, end, kind, typeKind) {
  const docToken = tokens.find((token) => token.kind === 'doc');
  const significant = tokens.filter((token) => token.kind !== 'doc');

  if (
    significant.length === 0
    || !isPublic(significant, typeKind)
    || (docToken?.value.includes('@internal') ?? false)
  ) {
    return;
  }

  const first = significant[0];
  const rawSignature = source.slice(first.start, end).trim().replace(/\s*\{$/, '');
  const signature = normalizeSignature(rawSignature, columnAt(source, first.start));
  const name = memberName(significant, kind);

  if (name === undefined) {
    return;
  }

  members.push({
    name,
    kind,
    line: lineAt(source, first.start),
    doc: parseDoc(docToken?.value),
    signature,
  });
}

function isPublic(tokens, typeKind) {
  if (tokens.some((token) => token.value === 'private' || token.value === 'protected')) {
    return false;
  }

  return typeKind === 'interface'
    || tokens.some((token) => token.value === 'public')
    || tokens.some((token) => token.value === 'case')
    || tokens.some((token) => token.value === 'const');
}

function memberName(tokens, kind) {
  if (kind === 'method') {
    const functionIndex = tokens.findIndex((token) => token.value === 'function');
    const name = tokens.slice(functionIndex + 1).find((token) => token.kind === 'word');

    return name === undefined ? undefined : `${name.value}()`;
  }

  if (kind === 'property') {
    return tokens.find((token) => token.kind === 'variable')?.value;
  }

  if (kind === 'case') {
    const markerIndex = tokens.findIndex((token) => token.value === 'case');

    return tokens.slice(markerIndex + 1).find((token) => token.kind === 'word')?.value;
  }

  const markerIndex = tokens.findIndex((token) => token.value === 'const');
  const equalsIndex = tokens.findIndex((token, index) => index > markerIndex && token.value === '=');
  const declaration = tokens.slice(markerIndex + 1, equalsIndex === -1 ? undefined : equalsIndex);

  return declaration.findLast((token) => token.kind === 'word')?.value;
}

function normalizeSignature(signature, baseIndent) {
  const lines = signature.split('\n');

  for (let index = 1; index < lines.length; index += 1) {
    lines[index] = lines[index].slice(Math.min(baseIndent, lines[index].match(/^\s*/)[0].length));
  }

  while (lines.length > 0 && lines[0].trim() === '') {
    lines.shift();
  }

  while (lines.length > 0 && lines.at(-1).trim() === '') {
    lines.pop();
  }

  const indents = lines
    .filter((line) => line.trim() !== '')
    .map((line) => line.match(/^\s*/)[0].length);
  const indent = Math.min(...indents);

  return lines.map((line) => line.slice(indent).replace(/\s+$/, '')).join('\n');
}

function parseDoc(comment) {
  if (comment === undefined) {
    return { description: '', tags: [] };
  }

  const lines = comment
    .replace(/^\/\*\*\s?/, '')
    .replace(/\s?\*\/$/, '')
    .split('\n')
    .map((line) => line.replace(/^\s*\*\s?/, '').replace(/\s+$/, ''));
  const description = [];
  const tags = [];
  let currentTag;

  for (const line of lines) {
    if (line.startsWith('@')) {
      currentTag = line;
      tags.push(currentTag);
      continue;
    }

    if (currentTag !== undefined && line !== '') {
      tags[tags.length - 1] = `${tags.at(-1)} ${line.trim()}`;
      continue;
    }

    if (currentTag === undefined) {
      description.push(line);
    }
  }

  return {
    description: trimBlankLines(description).join('\n'),
    tags: tags.filter((tag) => !tag.startsWith('@internal') && !tag.startsWith('@phpstan-')),
  };
}

function trimBlankLines(lines) {
  while (lines[0] === '') {
    lines.shift();
  }

  while (lines.at(-1) === '') {
    lines.pop();
  }

  return lines;
}

function tokenize(source) {
  const tokens = [];
  let index = 0;

  while (index < source.length) {
    const character = source[index];

    if (/\s/u.test(character)) {
      index += 1;
      continue;
    }

    if (source.startsWith('/**', index)) {
      const end = source.indexOf('*/', index + 3);

      if (end === -1) {
        throw new Error('The API parser found an unterminated PHPDoc comment.');
      }

      tokens.push({ kind: 'doc', value: source.slice(index, end + 2), start: index, end: end + 2 });
      index = end + 2;
      continue;
    }

    if (source.startsWith('/*', index)) {
      const end = source.indexOf('*/', index + 2);

      if (end === -1) {
        throw new Error('The API parser found an unterminated block comment.');
      }

      index = end + 2;
      continue;
    }

    if (source.startsWith('//', index) || (character === '#' && source[index + 1] !== '[')) {
      const end = source.indexOf('\n', index + 1);
      index = end === -1 ? source.length : end + 1;
      continue;
    }

    if (source.startsWith('<<<', index)) {
      const start = index;
      const headerEnd = source.indexOf('\n', index + 3);
      const header = source.slice(index, headerEnd);
      const label = header.match(/^<<<\s*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1\s*$/u)?.[2];

      if (headerEnd === -1 || label === undefined) {
        throw new Error('The API parser found an invalid heredoc header.');
      }

      const terminator = new RegExp(`\\n[\\t ]*${label}(?=[;,) ]?\\r?\\n)`, 'gu');
      terminator.lastIndex = headerEnd;
      const match = terminator.exec(source);

      if (match === null) {
        throw new Error('The API parser found an unterminated heredoc string.');
      }

      index = match.index + match[0].length;
      tokens.push({ kind: 'string', value: source.slice(start, index), start, end: index });
      continue;
    }

    if (character === "'" || character === '"' || character === '`') {
      const quote = character;
      const start = index;
      index += 1;

      while (index < source.length) {
        if (source[index] === '\\') {
          index += 2;
          continue;
        }

        if (source[index] === quote) {
          index += 1;
          break;
        }

        index += 1;
      }

      tokens.push({ kind: 'string', value: source.slice(start, index), start, end: index });
      continue;
    }

    if (character === '$') {
      const start = index;
      index += 1;

      while (index < source.length && /[A-Za-z0-9_\x80-\xff]/u.test(source[index])) {
        index += 1;
      }

      tokens.push({ kind: 'variable', value: source.slice(start, index), start, end: index });
      continue;
    }

    if (/[A-Za-z_\x80-\xff]/u.test(character)) {
      const start = index;
      index += 1;

      while (index < source.length && /[A-Za-z0-9_\x80-\xff]/u.test(source[index])) {
        index += 1;
      }

      tokens.push({ kind: 'word', value: source.slice(start, index), start, end: index });
      continue;
    }

    const operator = ['?->', '...', '::', '->', '=>', '??', '&&', '||', '<=', '>=', '===', '!==', '==', '!=']
      .find((candidate) => source.startsWith(candidate, index));
    const value = operator ?? character;
    tokens.push({ kind: 'symbol', value, start: index, end: index + value.length });
    index += value.length;
  }

  return tokens;
}

function lineAt(source, offset) {
  return source.slice(0, offset).split('\n').length;
}

function columnAt(source, offset) {
  return offset - source.lastIndexOf('\n', offset - 1) - 1;
}

function renderIndex() {
  const lines = [
    '<!-- This file is generated by website/scripts/generate-api-reference.mjs. -->',
    '',
    '# Public code API',
    '',
    'This reference lists the public Greenlight PHP API.',
    '',
    'Use the task guides for workflows and examples. Use these pages for exact signatures and PHPDoc types.',
    '',
    '## API sections',
    '',
  ];

  for (const section of sections) {
    lines.push(`- [${section.title}](${section.id}.md) — ${section.description}`);
  }

  lines.push('');

  return `${lines.join('\n').trimEnd()}\n`;
}

function renderSection(section, types) {
  const lines = [
    '<!-- This file is generated by website/scripts/generate-api-reference.mjs. -->',
    '',
    `# ${section.title}`,
    '',
    section.description,
    '',
    'These signatures are the public API.',
    '',
  ];

  for (const type of types) {
    lines.push(
      `## \`${type.headingName}\``,
      '',
      `Namespace: \`${type.name.slice(0, type.name.lastIndexOf('\\'))}\``,
      '',
    );

    if (type.doc.description !== '') {
      lines.push(type.doc.description, '');
    }

    lines.push(
      '```php',
      type.signature,
      '```',
      '',
      `[View source](https://github.com/ben-challis/greenlight/blob/main/${type.file}#L${type.line})`,
      '',
    );

    if (type.doc.tags.length > 0) {
      lines.push('PHPDoc:', '', ...type.doc.tags.map((tag) => `- \`${escapeBackticks(tag)}\``), '');
    }

    if (type.members.length === 0) {
      lines.push('This type does not declare public members.', '');
      continue;
    }

    for (const member of type.members) {
      lines.push(`### \`${member.name}\``, '');

      if (member.doc.description !== '') {
        lines.push(member.doc.description, '');
      }

      lines.push('```php', member.signature, '```', '');

      if (member.doc.tags.length > 0) {
        lines.push('PHPDoc:', '', ...member.doc.tags.map((tag) => `- \`${escapeBackticks(tag)}\``), '');
      }

      lines.push(
        `[View source](https://github.com/ben-challis/greenlight/blob/main/${type.file}#L${member.line})`,
        '',
      );
    }
  }

  return `${lines.join('\n').trimEnd()}\n`;
}

function escapeBackticks(value) {
  return value.replaceAll('`', '\\`');
}

function collectUnformattedIdentifiers(problems, owner, description) {
  const identifier = /(?:#\[[^\]]+\]|\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*\(\))?|(?:[A-Za-z_\\][A-Za-z0-9_\\]*::)?[A-Za-z_][A-Za-z0-9_]*\(\)|\b[a-z][a-z0-9]*(?:[A-Z][A-Za-z0-9]*)+\b|\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b|\b[a-z][a-z0-9]*_[a-z0-9_]+\b|--[a-z][a-z-]*|\b[A-Za-z0-9_.-]+\.(?:php|neon|json|xml)\b)/gu;
  const parts = description.split(/(`[^`]*`)/gu);
  let prose = '';

  for (let index = 0; index < parts.length; index += 2) {
    prose += ` ${parts[index]}`;
  }

  const matches = [...prose.matchAll(identifier)].map((match) => match[0]);

  if (matches.length > 0) {
    problems.push(`${owner} (${matches.join(', ')})`);
  }
}
