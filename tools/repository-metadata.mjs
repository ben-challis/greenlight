import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

const metadataPath = new URL('../.github/repository-metadata.json', import.meta.url);
const labelsPath = new URL('../.github/labels.json', import.meta.url);
const labelerPath = new URL('../.github/labeler.yml', import.meta.url);
const deptracPath = new URL('../deptrac.yaml', import.meta.url);
const check = process.argv.includes('--check');

const metadata = JSON.parse(await readFile(metadataPath, 'utf8'));

validateMetadata(metadata);
await validateDeptracLayers(metadata.scopes);

const labels = [
  ...metadata.types.map((label) => prefixedLabel('type', label)),
  ...metadata.statuses.map((label) => prefixedLabel('status', label)),
  ...metadata.scopes.map((scope) => ({
    name: `scope/${scope.name}`,
    color: '5319E7',
    description: scope.description,
  })),
];
const labeler = Object.fromEntries(metadata.scopes.map((scope) => [
  `scope/${scope.name}`,
  [{
    'changed-files': [{
      'any-glob-to-any-file': scope.paths,
    }],
  }],
]));

await updateGeneratedFile(labelsPath, `${JSON.stringify(labels, null, 2)}\n`);
await updateGeneratedFile(labelerPath, `${JSON.stringify(labeler, null, 2)}\n`);

function prefixedLabel(prefix, label) {
  return {
    name: `${prefix}/${label.name}`,
    color: label.color,
    description: label.description,
  };
}

function validateMetadata(value) {
  const names = new Set();

  for (const [group, entries] of Object.entries({
    type: value.types,
    status: value.statuses,
    scope: value.scopes,
  })) {
    if (!Array.isArray(entries) || entries.length === 0) {
      throw new Error(`Metadata group "${group}" must contain labels.`);
    }

    for (const entry of entries) {
      const labelName = `${group}/${entry.name}`;

      if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(entry.name)) {
        throw new Error(`Label "${labelName}" must use lower kebab case.`);
      }

      if (names.has(labelName)) {
        throw new Error(`Label "${labelName}" is not unique.`);
      }

      names.add(labelName);

      if (typeof entry.description !== 'string' || entry.description.length > 100) {
        throw new Error(`Label "${labelName}" must have a description of 100 characters or fewer.`);
      }

      if (group !== 'scope' && !/^[0-9A-F]{6}$/.test(entry.color)) {
        throw new Error(`Label "${labelName}" must have a six-digit uppercase color.`);
      }

      if (group === 'scope') {
        if (!Array.isArray(entry.layers) || !Array.isArray(entry.paths) || entry.paths.length === 0) {
          throw new Error(`Scope "${entry.name}" must define layers and paths.`);
        }

        if (entry.paths.some((path) => typeof path !== 'string' || path.length === 0)) {
          throw new Error(`Scope "${entry.name}" has an invalid path.`);
        }
      }
    }
  }
}

async function validateDeptracLayers(scopes) {
  const deptrac = await readFile(deptracPath, 'utf8');
  const layerSection = deptrac.slice(deptrac.indexOf('  layers:'), deptrac.indexOf('  ruleset:'));
  const blocks = layerSection.split(/(?=    - name: )/u).slice(1);
  const repositoryLayers = blocks
    .filter((block) => block.includes('type: directory'))
    .map((block) => block.match(/^    - name: ([A-Za-z0-9]+)$/mu)?.[1]);
  const configuredLayers = scopes.flatMap((scope) => scope.layers);

  compareSets('Deptrac layers without scopes', repositoryLayers, configuredLayers);
  compareSets('Scopes with unknown Deptrac layers', configuredLayers, repositoryLayers);

  if (new Set(configuredLayers).size !== configuredLayers.length) {
    throw new Error('Each repository Deptrac layer must belong to one scope.');
  }
}

function compareSets(message, left, right) {
  const rightSet = new Set(right);
  const difference = left.filter((item) => !rightSet.has(item));

  if (difference.length > 0) {
    throw new Error(`${message}: ${difference.join(', ')}.`);
  }
}

async function updateGeneratedFile(path, expected) {
  if (!check) {
    await writeFile(path, expected);
    return;
  }

  let actual;

  try {
    actual = await readFile(path, 'utf8');
  } catch {
    throw new Error(`Generated file "${path.pathname}" does not exist.`);
  }

  if (actual !== expected) {
    throw new Error(`Generated file "${path.pathname}" is not current. Run "npm run generate:metadata".`);
  }
}
