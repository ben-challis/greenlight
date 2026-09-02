import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const script = resolve(dirname(fileURLToPath(import.meta.url)), 'generate-api-reference.mjs');

test('each public top-level declaration appears in the reference', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'Events.php'), `<?php

namespace Greenlight\\Event;

final class FirstEvent {}

final class SecondEvent {}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /## `FirstEvent`/);
    assert.match(reference, /## `SecondEvent`/);
    assert.match(reference, /## `SecondEvent`[\s\S]*?```php\nfinal class SecondEvent\n```/);
    assert.doesNotMatch(reference, /final class FirstEvent \{\}[\s\S]*?final class SecondEvent/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});

test('member attributes do not leave partial signatures', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'Event.php'), `<?php

namespace Greenlight\\Event;

final class Event
{
    #[\\Override]
    public function name(): string {}
}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /```php\npublic function name\(\): string\n```/);
    assert.doesNotMatch(reference, /\\Override/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});

test('method comments before braces do not enter signatures', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'Event.php'), `<?php

namespace Greenlight\\Event;

final class Event
{
    public function lineComment(): string // The implementation has a line comment.
    {
        return '';
    }

    public function blockComment(): string /* The implementation has a block comment. */
    {
        return '';
    }
}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /```php\npublic function lineComment\(\): string\n```/);
    assert.match(reference, /```php\npublic function blockComment\(\): string\n```/);
    assert.doesNotMatch(reference, /implementation has/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});

test('a secondary internal declaration cannot leak through a public type', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-'));

  try {
    await writeFile(resolve(sourceRoot, 'Types.php'), `<?php

namespace Greenlight\\Event;

final class PublicType
{
    public function leak(InternalType $value): void {}
}

/** @internal */
final class InternalType {}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      '--validate-only',
    ], { encoding: 'utf8' });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /PublicType::leak\(\) signature references internal type "Greenlight\\Event\\InternalType"/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
  }
});

test('public API validation rejects each internal type reference surface', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-'));

  try {
    await writeFile(resolve(sourceRoot, 'InternalType.php'), `<?php

namespace Greenlight\\Internal;

/**
 * Supplies the internal fixture type.
 *
 * @internal
 */
class InternalType {}
`);
    await writeFile(resolve(sourceRoot, 'PublicLeak.php'), `<?php

namespace Greenlight\\Fixture;

use Greenlight\\Internal\\{InternalType as Hidden};

/**
 * Supplies the public fixture type.
 *
 * @template T of Hidden
 */
class PublicLeak extends Hidden
{
    public Hidden $property;

    /**
     * @param Hidden $value
     * @return Hidden
     * @throws Hidden
     */
    public function leak(Hidden $value): Hidden {}
}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      '--validate-only',
    ], { encoding: 'utf8' });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /PublicLeak declaration references internal type/);
    assert.match(result.stderr, /PublicLeak::\$property signature references internal type/);
    assert.match(result.stderr, /PublicLeak::leak\(\) signature references internal type/);
    assert.match(result.stderr, /PublicLeak @template references internal type/);
    assert.match(result.stderr, /PublicLeak::leak\(\) @param references internal type/);
    assert.match(result.stderr, /PublicLeak::leak\(\) @return references internal type/);
    assert.match(result.stderr, /PublicLeak::leak\(\) @throws references internal type/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
  }
});

test('internal implemented interfaces project their public ancestors', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'Event.php'), `<?php

namespace Greenlight\\Event;

interface Event {}
`);
    await writeFile(resolve(sourceRoot, 'WireEvent.php'), `<?php

namespace Greenlight\\Internal;

use Greenlight\\Event\\Event as PublishedEvent;

/** @internal */
interface WireEvent extends PublishedEvent {}
`);
    await writeFile(resolve(sourceRoot, 'BuiltInEvent.php'), `<?php

namespace Greenlight\\Event;

use Greenlight\\Internal\\WireEvent as MachineEvent;

final class BuiltInEvent implements MachineEvent {}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /final class BuiltInEvent implements Event/);
    assert.doesNotMatch(reference, /MachineEvent|WireEvent/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});

test('an internal implemented marker without a public ancestor is rejected', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-'));

  try {
    await writeFile(resolve(sourceRoot, 'InternalMarker.php'), `<?php

namespace Greenlight\\Internal;

/** @internal */
interface InternalMarker {}
`);
    await writeFile(resolve(sourceRoot, 'PublicType.php'), `<?php

namespace Greenlight\\Event;

use Greenlight\\Internal\\InternalMarker as HiddenMarker;

final class PublicType implements HiddenMarker {}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      '--validate-only',
    ], { encoding: 'utf8' });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /PublicType declaration references internal type "Greenlight\\Internal\\InternalMarker"/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
  }
});

test('one internal marker path prevents partial interface projection', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-'));

  try {
    await writeFile(resolve(sourceRoot, 'Event.php'), `<?php

namespace Greenlight\\Event;

interface Event {}
`);
    await writeFile(resolve(sourceRoot, 'InternalMarker.php'), `<?php

namespace Greenlight\\Internal;

/** @internal */
interface InternalMarker {}
`);
    await writeFile(resolve(sourceRoot, 'PartiallyProjectable.php'), `<?php

namespace Greenlight\\Internal;

use Greenlight\\Event\\Event as PublishedEvent;

/** @internal */
interface PartiallyProjectable extends PublishedEvent, InternalMarker {}
`);
    await writeFile(resolve(sourceRoot, 'PublicType.php'), `<?php

namespace Greenlight\\Event;

use Greenlight\\Internal\\PartiallyProjectable as MachineEvent;

final class PublicType implements MachineEvent {}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      '--validate-only',
    ], { encoding: 'utf8' });

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /PublicType declaration references internal type "Greenlight\\Internal\\PartiallyProjectable"/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
  }
});
