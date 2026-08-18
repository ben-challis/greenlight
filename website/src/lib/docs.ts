import type { CollectionEntry } from 'astro:content';

import { sitePath } from './paths';

export const docSections = [
  {
    title: 'Start',
    items: [
      {
        id: 'getting-started',
        title: 'Start with Greenlight',
        description: 'This guide explains how to install Greenlight, write your first test, and use the runner.',
      },
      {
        id: 'migrating-from-phpunit',
        title: 'Move from PHPUnit',
        description: 'This guide compares PHPUnit and Greenlight concepts and explains the required changes.',
      },
    ],
  },
  {
    title: 'Use Greenlight',
    items: [
      {
        id: 'configuration',
        title: 'Configuration',
        description: 'This reference explains configuration for suites, workers, resource limits, coverage, and the CLI.',
      },
      {
        id: 'attributes',
        title: 'Attributes',
        description: 'This reference explains attributes for tests, hooks, data sets, and execution.',
      },
      {
        id: 'expectations',
        title: 'Expectations',
        description: 'This reference explains value, exception, and temporal expectations.',
      },
      {
        id: 'test-doubles',
        title: 'Doubles',
        description: 'This guide explains mocks, stubs, and spies.',
      },
      {
        id: 'attachments',
        title: 'Attachments',
        description: 'This guide explains how to attach structured values and files to one test result.',
      },
    ],
  },
  {
    title: 'Extend',
    items: [
      {
        id: 'symfony',
        title: 'Symfony',
        description: 'This guide explains how tests use a kernel and receive container services.',
      },
      {
        id: 'laravel',
        title: 'Laravel',
        description: 'This guide explains how tests receive container services from a fresh Laravel application.',
      },
      {
        id: 'hyperf',
        title: 'Hyperf',
        description: 'This guide explains coroutine test attempts, AOP classes, and persistent or isolated containers.',
      },
      {
        id: 'psr',
        title: 'PSR applications',
        description: 'This guide explains how tests receive services from a PSR-11 container.',
      },
      {
        id: 'psr15',
        title: 'PSR-15 HTTP',
        description: 'This guide explains how tests send PSR-7 requests directly to PSR-15 applications.',
      },
      {
        id: 'phpstan',
        title: 'PHPStan',
        description: 'This guide explains how PHPStan checks matchers, data providers, and extension matchers.',
      },
      {
        id: 'plugins',
        title: 'Plugins',
        description: 'This guide explains plugins for hooks, retry deciders, harness services, expectations, and reporters.',
      },
    ],
  },
  {
    title: 'API reference',
    items: [
      {
        id: 'api',
        title: 'Public code API',
        description: 'This reference lists the public Greenlight PHP API.',
      },
      {
        id: 'api-attributes',
        title: 'Attributes and conditions',
        description: 'This reference lists the attributes and conditions that control test discovery and execution.',
      },
      {
        id: 'api-configuration',
        title: 'Configuration API',
        description: 'This reference lists the builders that configure Greenlight runs.',
      },
      {
        id: 'api-artifacts',
        title: 'Artifact API',
        description: 'This reference lists attachment values, retention rules, and attachment operations.',
      },
      {
        id: 'api-events',
        title: 'Event API',
        description: 'This reference lists the events that plugins and reporters receive during a run.',
      },
      {
        id: 'api-results',
        title: 'Result API',
        description: 'This reference lists test outcomes, diagnostics, failure details, and result values.',
      },
      {
        id: 'api-test-contracts',
        title: 'Test contracts API',
        description: 'This reference lists test metadata, skip signals, conditions, and wire contracts.',
      },
      {
        id: 'api-expectations',
        title: 'Expectations API',
        description: 'This reference lists immediate and temporal expectation types.',
      },
      {
        id: 'api-doubles',
        title: 'Doubles API',
        description: 'This reference lists double factories, argument matchers, captors, and mock plans.',
      },
      {
        id: 'api-fixtures-harness',
        title: 'Fixtures and harness',
        description: 'This reference lists fixtures and harness service contracts.',
      },
      {
        id: 'api-plugins',
        title: 'Plugin API',
        description: 'This reference lists plugin capabilities and lifecycle callback contracts.',
      },
      {
        id: 'api-reporting',
        title: 'Reporter API',
        description: 'This reference lists reporter and output contracts.',
      },
      {
        id: 'api-integrations',
        title: 'Integration API',
        description: 'This reference lists public integration types for Hyperf, Laravel, PSR standards, Rector, and Symfony.',
      },
    ],
  },
  {
    title: 'Evidence',
    items: [
      {
        id: 'benchmarks',
        title: 'Benchmarks',
        description: 'This guide explains the generated benchmark method, results, and limitations.',
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
    throw new Error(`Documentation metadata does not exist for "${id}".`);
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
        ? `No metadata exists for these documentation entries: ${missingMetadata.join(', ')}.`
        : undefined,
      missingFiles.length > 0
        ? `No Markdown file exists for these metadata entries: ${missingFiles.join(', ')}.`
        : undefined,
    ]
      .filter(Boolean)
      .join(' ');

    throw new Error(`Documentation collection is incomplete. ${details}`);
  }
}
