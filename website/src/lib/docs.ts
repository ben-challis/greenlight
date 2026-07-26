import type { CollectionEntry } from 'astro:content';

import { sitePath } from './paths';

export const docSections = [
  {
    title: 'Start',
    items: [
      {
        id: 'getting-started',
        title: 'Getting started',
        description: 'Install Greenlight, write a first test, and understand the runner.',
      },
      {
        id: 'migrating-from-phpunit',
        title: 'Migrating from PHPUnit',
        description: 'Map familiar PHPUnit concepts onto Greenlight deliberately.',
      },
    ],
  },
  {
    title: 'Use Greenlight',
    items: [
      {
        id: 'configuration',
        title: 'Configuration',
        description: 'Configure suites, workers, coverage, failure policy, and the CLI.',
      },
      {
        id: 'attributes',
        title: 'Attributes',
        description: 'Reference every test, lifecycle, data, and execution attribute.',
      },
      {
        id: 'expectations',
        title: 'Expectations',
        description: 'Assert values, exceptions, and asynchronous state.',
      },
      {
        id: 'test-doubles',
        title: 'Doubles',
        description: 'Plan strict mocks, use inert stubs, and inspect spies.',
      },
      {
        id: 'attachments',
        title: 'Attachments',
        description: 'Attach structured values and files to individual test results.',
      },
    ],
  },
  {
    title: 'Extend',
    items: [
      {
        id: 'symfony',
        title: 'Symfony',
        description: 'Use kernel-aware tests and inject services from the container.',
      },
      {
        id: 'phpstan',
        title: 'PHPStan',
        description: 'Type-check matchers, providers, and custom expectations.',
      },
      {
        id: 'plugins',
        title: 'Writing plugins',
        description: 'Extend lifecycle, retries, services, expectations, and reporting.',
      },
    ],
  },
  {
    title: 'Evidence',
    items: [
      {
        id: 'benchmarks',
        title: 'Benchmarks',
        description: 'Review the generated benchmark method, results, and limitations.',
      },
    ],
  },
] as const;

export type DocId = (typeof docSections)[number]['items'][number]['id'];
export type DocDefinition = (typeof docSections)[number]['items'][number] & {
  section: string;
};

export const docs = docSections.flatMap((section) =>
  section.items.map((item) => ({ ...item, section: section.title })),
) as readonly DocDefinition[];

export function docById(id: string): DocDefinition {
  const doc = docs.find((candidate) => candidate.id === id);

  if (!doc) {
    throw new Error(`No documentation metadata exists for "${id}".`);
  }

  return doc;
}

export function docPath(id: string): string {
  return sitePath(`docs/${id}/`);
}

export function adjacentDocs(id: string): {
  previous?: DocDefinition;
  next?: DocDefinition;
} {
  const index = docs.findIndex((doc) => doc.id === id);

  if (index === -1) {
    return {};
  }

  return {
    previous: docs[index - 1],
    next: docs[index + 1],
  };
}

export function assertCompleteDocCollection(
  entries: readonly CollectionEntry<'guides'>[],
): void {
  const entryIds = new Set(entries.map((entry) => entry.id.replace(/\.md$/, '')));
  const configuredIds = new Set(docs.map((doc) => doc.id));

  const missingMetadata = [...entryIds].filter((id) => !configuredIds.has(id as DocId));
  const missingFiles = [...configuredIds].filter((id) => !entryIds.has(id));

  if (missingMetadata.length > 0 || missingFiles.length > 0) {
    const details = [
      missingMetadata.length > 0
        ? `missing metadata: ${missingMetadata.join(', ')}`
        : undefined,
      missingFiles.length > 0
        ? `missing Markdown: ${missingFiles.join(', ')}`
        : undefined,
    ]
      .filter(Boolean)
      .join('; ');

    throw new Error(`Documentation collection is incomplete (${details}).`);
  }
}
