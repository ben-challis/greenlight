import { readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptDirectory, '../..');
const sourceRootArgument = process.argv.find((argument) => argument.startsWith('--source-root='));
const sourceRoot = sourceRootArgument === undefined
  ? resolve(repositoryRoot, 'src')
  : resolve(sourceRootArgument.slice('--source-root='.length));
const documentationRootArgument = process.argv.find((argument) => argument.startsWith('--documentation-root='));
const documentationRoot = documentationRootArgument === undefined
  ? resolve(repositoryRoot, 'docs')
  : resolve(documentationRootArgument.slice('--documentation-root='.length));

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
    prefixes: ['Greenlight\\Artifact\\'],
  },
  {
    id: 'api-coverage',
    title: 'Coverage API',
    description: 'This reference lists coverage maps and per-file line coverage values.',
    prefixes: ['Greenlight\\Coverage\\'],
  },
  {
    id: 'api-events',
    title: 'Event API',
    description: 'This reference lists the events that plugins and reporters receive during a run.',
    prefixes: ['Greenlight\\Event\\'],
  },
  {
    id: 'api-results',
    title: 'Result API',
    description: 'This reference lists test outcomes, diagnostics, failure details, and result values.',
    prefixes: ['Greenlight\\Result\\'],
  },
  {
    id: 'api-test-contracts',
    title: 'Test contracts API',
    description: 'This reference lists test definitions, policies, and skip signals.',
    prefixes: ['Greenlight\\Test\\'],
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
    id: 'api-sandboxes',
    title: 'Sandbox API',
    description: 'This reference lists the sandboxes that isolate temporary test state.',
    prefixes: ['Greenlight\\Sandbox\\'],
  },
  {
    id: 'api-harness',
    title: 'Harness API',
    description: 'This reference lists harness service and lifecycle contracts.',
    prefixes: ['Greenlight\\Harness\\'],
  },
  {
    id: 'api-integration-fixtures',
    title: 'Integration fixture API',
    description: 'This reference lists integration fixture definitions, contexts, resources, and sensitive values.',
    prefixes: ['Greenlight\\IntegrationFixture\\'],
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
    description: 'This reference lists public integration types for Hyperf, Laravel, PSR standards, Rector, Symfony, and Tempest.',
    prefixes: [
      'Greenlight\\Hyperf\\',
      'Greenlight\\Laravel\\',
      'Greenlight\\PhpStan\\',
      'Greenlight\\Psr11\\',
      'Greenlight\\Psr15\\',
      'Greenlight\\Rector\\',
      'Greenlight\\Symfony\\',
      'Greenlight\\Tempest\\',
    ],
  },
];

const check = process.argv.includes('--check');
const validateOnly = process.argv.includes('--validate-only');

const sourceFiles = await filesBelow(sourceRoot);
const parsedTypes = [];

for (const file of sourceFiles.filter((path) => path.endsWith('.php'))) {
  const source = await readFile(file, 'utf8');
  const sourceFile = relative(repositoryRoot, file);
  let types;

  try {
    types = parseTypes(source, sourceFile);
  } catch (error) {
    throw new Error(`${sourceFile}: ${error.message}`, { cause: error });
  }

  parsedTypes.push(...types);
}

const typesByName = new Map(parsedTypes.map((type) => [type.name, type]));
const publicTypes = parsedTypes.filter((type) => !type.internal);
const contractErrors = internalContractErrors(parsedTypes);

if (contractErrors.length > 0) {
  throw new Error(`Public API declarations reference internal types:\n${contractErrors.join('\n')}`);
}

if (validateOnly) {
  console.log(`The public API declarations do not reference internal types (${publicTypes.length} public types).`);
  process.exit(0);
}

for (const type of publicTypes) {
  type.members = effectiveMembers(type, typesByName);
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

  // Qualify short names that occur more than once. This rule keeps each
  // section title and anchor unique. Examples are the Laravel and Symfony Service attributes.
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

function parseTypes(source, file) {
  const tokens = tokenize(source);
  const namespace = namespaceName(tokens);
  const types = [];
  let depth = 0;
  let boundaryIndex = -1;

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token.value === '{') {
      depth += 1;
      continue;
    }

    if (token.value === '}') {
      depth -= 1;

      if (depth === 0) {
        boundaryIndex = index;
      }

      continue;
    }

    if (depth === 0 && token.value === ';') {
      boundaryIndex = index;
      continue;
    }

    if (
      depth === 0
      && token.kind === 'word'
      && ['class', 'interface', 'enum', 'trait'].includes(token.value)
      && tokens[index - 1]?.value !== '::'
    ) {
      types.push(parseTypeDeclaration(source, file, tokens, namespace, index, boundaryIndex));
    }
  }

  return types;
}

function parseTypeDeclaration(source, file, tokens, namespace, declarationIndex, boundaryIndex) {
  const kind = tokens[declarationIndex].value;
  const openIndex = tokens.findIndex((token, index) => index > declarationIndex && token.value === '{');

  if (openIndex === -1) {
    throw new Error(`${file}: The API parser cannot find the type body.`);
  }

  const nameToken = tokens.slice(declarationIndex + 1, openIndex).find((token) => token.kind === 'word');

  if (nameToken === undefined) {
    throw new Error(`${file}: The API parser cannot find the type name.`);
  }

  const closeIndex = matchingBrace(tokens, openIndex);
  const declarationTokens = tokens.slice(boundaryIndex + 1, openIndex);
  const typeDoc = [...declarationTokens].reverse().find((token) => token.kind === 'doc');
  const signatureStart = firstSignatureToken(declarationTokens);
  const signature = source.slice(signatureStart.start, tokens[openIndex].start).trim();
  const imports = importedTypes(source.slice(0, tokens[declarationIndex].start));
  const declaringContext = { namespace, imports };
  const members = parseMembers(source, tokens, openIndex, closeIndex, kind)
    .map((member) => ({ ...member, file, declaringContext }));
  const shortName = nameToken.value;

  return {
    name: namespace === '' ? shortName : `${namespace}\\${shortName}`,
    shortName,
    namespace,
    imports,
    kind,
    file,
    line: lineAt(source, tokens[declarationIndex].start),
    doc: parseDoc(typeDoc?.value),
    internal: hasInternalTag(typeDoc?.value),
    signature,
    members,
  };
}

function effectiveMembers(type, typesByName, active = new Set()) {
  if (active.has(type.name)) {
    throw new Error(`Public API type inheritance contains a cycle at "${type.name}".`);
  }

  const nextActive = new Set(active).add(type.name);
  const members = [];
  const parentName = type.signature.match(/\bextends\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/u)?.[1];
  const parent = referencedType(type, parentName, typesByName);

  if (parent !== undefined) {
    members.push(...effectiveMembers(parent, typesByName, nextActive)
      .filter((member) => member.name !== '__construct()'));
  }

  for (const tag of type.doc.tags) {
    const mixinName = tag.match(/^@mixin\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/u)?.[1];
    const mixin = referencedType(type, mixinName, typesByName);

    if (mixin !== undefined) {
      members.push(...effectiveMembers(mixin, typesByName, nextActive)
        .filter((member) => member.name !== '__construct()')
        .map((member) => projectMixinMember(member, mixin)));
    }
  }

  for (const member of type.members) {
    const inheritedIndex = members.findIndex((candidate) => candidate.name === member.name);

    if (inheritedIndex === -1) {
      members.push(member);
    } else {
      members[inheritedIndex] = member;
    }
  }

  return members;
}

function referencedType(owner, reference, typesByName) {
  if (reference === undefined) {
    return undefined;
  }

  return typesByName.get(resolveTypeName(reference, owner));
}

function projectMixinMember(member, mixin) {
  const replaceSelf = (value) => value.replace(/\bself\b/gu, `\\${mixin.name}`);

  return {
    ...member,
    signature: replaceSelf(member.signature),
    doc: {
      ...member.doc,
      tags: member.doc.tags.map(replaceSelf),
    },
  };
}

function hasInternalTag(comment) {
  if (comment === undefined) {
    return false;
  }

  return comment
    .replace(/^\/\*\*\s?/u, '')
    .replace(/\s?\*\/$/u, '')
    .split('\n')
    .map((line) => line.replace(/^\s*\*\s?/u, '').trim())
    .some((line) => /^@internal\b/u.test(line));
}

function importedTypes(source) {
  const imports = new Map();
  const declarations = source.matchAll(/^use\s+(?!const\b|function\b)([^;]+);/gmu);

  for (const declaration of declarations) {
    const group = declaration[1].trim().match(/^([^{}]+)\{([^{}]+)\}$/u);
    const prefix = group?.[1] ?? '';
    const importedTypes = (group?.[2] ?? declaration[1]).split(',');

    for (const imported of importedTypes) {
      const match = imported.trim().match(/^([^\s]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/iu);

      if (match === null) {
        continue;
      }

      const name = `${prefix}${match[1]}`.replace(/^\\/u, '');
      const alias = match[2] ?? name.split('\\').at(-1);
      imports.set(alias, name);
    }
  }

  return imports;
}

function internalContractErrors(types) {
  const internalTypes = new Set(types.filter((type) => type.internal).map((type) => type.name));
  const errors = [];

  for (const type of types.filter((candidate) => !candidate.internal)) {
    addInternalReferenceErrors(
      errors,
      internalTypes,
      type,
      contractTypeSignature(type),
      type.line,
      `${type.name} declaration`,
    );
    addDocReferenceErrors(errors, internalTypes, type, type.doc.tags, type.line, type.name);

    for (const member of type.members) {
      addInternalReferenceErrors(
        errors,
        internalTypes,
        type,
        member.signature,
        member.line,
        `${type.name}::${member.name} signature`,
      );
      addDocReferenceErrors(
        errors,
        internalTypes,
        type,
        member.doc.tags,
        member.line,
        `${type.name}::${member.name}`,
      );
    }
  }

  return errors;
}

function addDocReferenceErrors(errors, internalTypes, type, tags, line, owner) {
  const typeTags = /^(?:@(?:extends|implements|method|mixin|param|property(?:-read|-write)?|return|throws|use|var)\b|@template(?:-covariant|-contravariant)?\b)/u;

  for (const tag of tags.filter((candidate) => typeTags.test(candidate))) {
    addInternalReferenceErrors(errors, internalTypes, type, tag, line, `${owner} ${tag.split(/\s/u, 1)[0]}`);
  }
}

function addInternalReferenceErrors(errors, internalTypes, type, contract, line, surface) {
  const references = new Set();
  const identifiers = contract.matchAll(/\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*/gu);

  for (const identifier of identifiers) {
    const resolved = resolveTypeName(identifier[0], type);

    if (internalTypes.has(resolved)) {
      references.add(resolved);
    }
  }

  for (const reference of references) {
    errors.push(`${type.file}:${line}: ${surface} references internal type "${reference}".`);
  }
}

function resolveTypeName(identifier, type) {
  if (identifier.startsWith('\\')) {
    return identifier.slice(1);
  }

  if (identifier.startsWith('Greenlight\\')) {
    return identifier;
  }

  const [first, ...remainder] = identifier.split('\\');
  const imported = type.imports.get(first);

  if (imported !== undefined) {
    return [imported, ...remainder].join('\\');
  }

  return type.namespace === '' ? identifier : `${type.namespace}\\${identifier}`;
}

function qualifySignature(signature, declaringContext, displayedNamespace, additionalExcludedStarts = new Set()) {
  const tokens = tokenize(signature);
  const excludedStarts = new Set(additionalExcludedStarts);

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (
      ['class', 'interface', 'enum', 'trait', 'case'].includes(token.value)
      && tokens[index - 1]?.value !== '::'
      && tokens[index + 1]?.kind === 'word'
    ) {
      excludedStarts.add(tokens[index + 1].start);
    }

    if (token.value === 'function') {
      const openIndex = tokens.findIndex((candidate, candidateIndex) => (
        candidateIndex > index && candidate.value === '('
      ));
      const name = tokens.slice(index + 1, openIndex === -1 ? undefined : openIndex)
        .findLast((candidate) => candidate.kind === 'word');

      if (name !== undefined) {
        excludedStarts.add(name.start);
      }
    }

    if (token.value === 'const') {
      const boundary = tokens.findIndex((candidate, candidateIndex) => (
        candidateIndex > index && ['=', ';'].includes(candidate.value)
      ));
      const declaration = tokens.slice(index + 1, boundary === -1 ? undefined : boundary);
      const name = declaration.findLast((candidate) => candidate.kind === 'word');

      if (name !== undefined) {
        excludedStarts.add(name.start);
      }
    }
  }

  const replacements = [];

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token.kind !== 'word') {
      continue;
    }

    let endIndex = index;

    while (tokens[endIndex + 1]?.value === '\\' && tokens[endIndex + 2]?.kind === 'word') {
      endIndex += 2;
    }

    const previous = tokens[index - 1];
    const next = tokens[endIndex + 1];

    if (
      previous?.value !== '\\'
      && !['::', '->', '?->'].includes(previous?.value)
      && next?.value !== ':'
      && !excludedStarts.has(token.start)
    ) {
      const end = tokens[endIndex].end;
      const identifier = signature.slice(token.start, end);
      const qualified = qualifiedTypeReference(identifier, declaringContext, displayedNamespace);

      if (qualified !== undefined && qualified !== identifier) {
        replacements.push({ start: token.start, end, value: qualified });
      }
    }

    index = endIndex;
  }

  let qualified = signature;

  for (const replacement of replacements.reverse()) {
    qualified = `${qualified.slice(0, replacement.start)}${replacement.value}${qualified.slice(replacement.end)}`;
  }

  return qualified;
}

function qualifiedTypeReference(identifier, declaringContext, displayedNamespace) {
  const [first] = identifier.split('\\');
  const imported = declaringContext.imports.get(first);
  const resolved = resolveTypeName(identifier, declaringContext);

  if (imported === undefined && !typesByName.has(resolved) && !identifier.startsWith('Greenlight\\')) {
    return undefined;
  }

  if (
    imported === undefined
    && !identifier.includes('\\')
    && declaringContext.namespace === displayedNamespace
  ) {
    return identifier;
  }

  return `\\${resolved}`;
}

function qualifyTypeExpression(expression, declaringContext, displayedNamespace) {
  return qualifySignature(expression, declaringContext, displayedNamespace);
}

function qualifyDocTag(tag, declaringContext, displayedNamespace) {
  const parameterTag = tag.match(/^(@(?:param|property(?:-read|-write)?|var)\s+)([\s\S]+)$/u);

  if (parameterTag !== null) {
    const variable = parameterTag[2].match(/\$[A-Za-z_][A-Za-z0-9_]*/u);

    if (variable !== null) {
      const type = parameterTag[2].slice(0, variable.index);

      return `${parameterTag[1]}${qualifyTypeExpression(type, declaringContext, displayedNamespace)}${parameterTag[2].slice(variable.index)}`;
    }
  }

  const templateTag = tag.match(/^(@template(?:-covariant|-contravariant)?\s+\S+\s+(?:of|as)\s+)([\s\S]+)$/u);

  if (templateTag !== null) {
    const end = phpDocTypeEnd(templateTag[2]);

    return `${templateTag[1]}${qualifyTypeExpression(templateTag[2].slice(0, end), declaringContext, displayedNamespace)}${templateTag[2].slice(end)}`;
  }

  const methodTag = tag.match(/^(@method\s+)([\s\S]+)$/u);

  if (methodTag !== null) {
    const end = phpDocMethodEnd(methodTag[2]);
    const declaration = methodTag[2].slice(0, end);
    const declarationTokens = tokenize(declaration);
    const openIndex = declarationTokens.findIndex((token) => token.value === '(');
    const methodName = declarationTokens.slice(0, openIndex).findLast((token) => token.kind === 'word');
    const excluded = methodName === undefined ? new Set() : new Set([methodName.start]);

    return `${methodTag[1]}${qualifySignature(declaration, declaringContext, displayedNamespace, excluded)}${methodTag[2].slice(end)}`;
  }

  const leadingTypeTag = tag.match(/^(@(?:extends|implements|mixin|return|throws|use|var)\s+)([\s\S]+)$/u);

  if (leadingTypeTag === null) {
    return tag;
  }

  const end = phpDocTypeEnd(leadingTypeTag[2]);

  return `${leadingTypeTag[1]}${qualifyTypeExpression(leadingTypeTag[2].slice(0, end), declaringContext, displayedNamespace)}${leadingTypeTag[2].slice(end)}`;
}

function phpDocTypeEnd(value) {
  const open = new Set(['(', '[', '{', '<']);
  const close = new Set([')', ']', '}', '>']);
  let depth = 0;

  for (let index = 0; index < value.length; index += 1) {
    const character = value[index];

    if (open.has(character)) {
      depth += 1;
      continue;
    }

    if (close.has(character)) {
      depth -= 1;
      continue;
    }

    if (depth !== 0 || !/\s/u.test(character)) {
      continue;
    }

    const previous = value.slice(0, index).trimEnd().at(-1);
    const next = value.slice(index).trimStart()[0];

    if (!'|&?:='.includes(previous) && !'|&?:='.includes(next)) {
      return index;
    }
  }

  return value.length;
}

function phpDocMethodEnd(value) {
  const open = value.indexOf('(');

  if (open === -1) {
    return phpDocTypeEnd(value);
  }

  let depth = 0;

  for (let index = open; index < value.length; index += 1) {
    if (value[index] === '(') {
      depth += 1;
    } else if (value[index] === ')') {
      depth -= 1;

      if (depth === 0) {
        return index + 1;
      }
    }
  }

  return value.length;
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
        addPromotedProperties(members, source, significant);
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
    index += 1;
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
  const visibilityTokens = kind === 'method'
    ? significant.slice(0, significant.findIndex((token) => token.value === 'function') + 1)
    : significant;

  if (
    significant.length === 0
    || !isPublic(visibilityTokens, typeKind)
    || hasInternalTag(docToken?.value)
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

function addPromotedProperties(members, source, tokens) {
  const functionIndex = tokens.findIndex((token) => token.value === 'function');
  const name = tokens.slice(functionIndex + 1).find((token) => token.kind === 'word');

  if (name?.value !== '__construct') {
    return;
  }

  const openIndex = tokens.findIndex((token, index) => index > functionIndex && token.value === '(');

  if (openIndex === -1) {
    return;
  }

  let depth = 0;
  let parameterStart = openIndex + 1;

  for (let index = parameterStart; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (['(', '[', '{'].includes(token.value)) {
      depth += 1;
      continue;
    }

    if (token.value === ')' && depth === 0) {
      addPromotedProperty(members, source, tokens.slice(parameterStart, index));
      return;
    }

    if ([')', ']', '}'].includes(token.value)) {
      depth -= 1;
      continue;
    }

    if (token.value === ',' && depth === 0) {
      addPromotedProperty(members, source, tokens.slice(parameterStart, index));
      parameterStart = index + 1;
    }
  }
}

function addPromotedProperty(members, source, tokens) {
  const docToken = tokens.find((token) => token.kind === 'doc');
  const significant = tokens.filter((token) => token.kind !== 'doc');
  const variable = significant.find((token) => token.kind === 'variable');

  if (variable === undefined || !significant.some((token) => token.value === 'public')) {
    return;
  }

  const first = significant[0];

  members.push({
    name: variable.value,
    kind: 'property',
    line: lineAt(source, first.start),
    doc: parseDoc(docToken?.value),
    signature: normalizeSignature(source.slice(first.start, variable.end), columnAt(source, first.start)),
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
      qualifySignature(publicTypeSignature(type), type, type.namespace),
      '```',
      '',
      `[View source](https://github.com/ben-challis/greenlight/blob/main/${type.file}#L${type.line})`,
      '',
    );

    const typeTags = publicTypeTags(type);

    if (typeTags.length > 0) {
      lines.push(
        'PHPDoc:',
        '',
        ...typeTags.map((tag) => `- \`${escapeBackticks(qualifyDocTag(tag, type, type.namespace))}\``),
        '',
      );
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

      lines.push(
        '```php',
        qualifySignature(member.signature, member.declaringContext, type.namespace),
        '```',
        '',
      );

      if (member.doc.tags.length > 0) {
        lines.push(
          'PHPDoc:',
          '',
          ...member.doc.tags.map((tag) => (
            `- \`${escapeBackticks(qualifyDocTag(tag, member.declaringContext, type.namespace))}\``
          )),
          '',
        );
      }

      lines.push(
        `[View source](https://github.com/ben-challis/greenlight/blob/main/${member.file}#L${member.line})`,
        '',
      );
    }
  }

  return `${lines.join('\n').trimEnd()}\n`;
}

function publicTypeSignature(type) {
  const parentName = type.signature.match(/\bextends\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/u)?.[1];
  const parent = referencedType(type, parentName, typesByName);
  const signature = parent?.internal === true
    ? type.signature.replace(/\s+extends\s+[A-Za-z_\\][A-Za-z0-9_\\]*/u, '')
    : type.signature;

  return projectImplementedInterfaces(type, signature);
}

function contractTypeSignature(type) {
  return projectImplementedInterfaces(type, type.signature);
}

function projectImplementedInterfaces(type, signature) {
  const interfaces = signature.match(/\s+implements\s+([\s\S]+)$/u);

  if (interfaces === null) {
    return signature;
  }

  const projected = new Map();

  for (const reference of interfaces[1].split(',').map((name) => name.trim())) {
    const projection = publicInterfaceProjection(type, reference);

    if (!projection.safe) {
      projected.set(resolveTypeName(reference, type), reference);
      continue;
    }

    for (const interfaceType of projection.types) {
      projected.set(interfaceType.name, publicTypeReference(type, interfaceType));
    }
  }

  const declaration = signature.slice(0, interfaces.index);

  return projected.size === 0
    ? declaration
    : `${declaration} implements ${[...projected.values()].join(', ')}`;
}

function publicInterfaceProjection(owner, reference, active = new Set()) {
  const interfaceType = typesByName.get(resolveTypeName(reference, owner));

  if (interfaceType === undefined) {
    const name = resolveTypeName(reference, owner);
    const separator = name.lastIndexOf('\\');

    return {
      safe: true,
      types: [{
        name,
        shortName: name.slice(separator + 1),
        namespace: separator === -1 ? '' : name.slice(0, separator),
      }],
    };
  }

  if (!interfaceType.internal) {
    return { safe: true, types: [interfaceType] };
  }

  if (active.has(interfaceType.name)) {
    throw new Error(`Public API interface inheritance contains a cycle at "${interfaceType.name}".`);
  }

  const parents = interfaceType.signature.match(/\bextends\s+([\s\S]+)$/u)?.[1];

  if (parents === undefined) {
    return { safe: false, types: [] };
  }

  const nextActive = new Set(active).add(interfaceType.name);
  const paths = parents
    .split(',')
    .map((name) => name.trim())
    .map((name) => publicInterfaceProjection(interfaceType, name, nextActive));

  return {
    safe: paths.every((path) => path.safe && path.types.length > 0),
    types: paths.flatMap((path) => path.types),
  };
}

function publicTypeReference(owner, referenced) {
  if (referenced.namespace === owner.namespace) {
    return referenced.shortName;
  }

  for (const [alias, name] of owner.imports) {
    if (name === referenced.name) {
      return alias;
    }
  }

  return `\\${referenced.name}`;
}

function publicTypeTags(type) {
  return type.doc.tags.filter((tag) => {
    const parentName = tag.match(/^@extends\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/u)?.[1];
    const parent = referencedType(type, parentName, typesByName);

    return parent?.internal !== true;
  });
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
