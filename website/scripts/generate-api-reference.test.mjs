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

test('rendered types qualify imports without changing PHPDoc descriptions', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'Hidden.php'), `<?php

namespace Greenlight\\Internal;

/** @internal */
final class Hidden {}
`);
    await writeFile(resolve(sourceRoot, 'ImportedEvent.php'), `<?php

namespace Greenlight\\Event;

use Greenlight\\Internal\\Hidden as UnusedInternal;
use Psr\\Http\\Message\\ResponseInterface;
use Vendor\\ChildTypes\\{Context as SharedContext, Failure as ExternalFailure};

/**
 * @template TResponse of ResponseInterface
 * @method ResponseInterface synthesize(SharedContext $context) A SharedContext method description.
 */
final class ImportedEvent
{
    /**
     * @param SharedContext $context A SharedContext parameter description.
     * @return ResponseInterface A ResponseInterface return description.
     * @throws ExternalFailure when the external operation fails
     */
    public function direct(
        #[ResponseInterface]
        ResponseInterface $response,
        SharedContext $context = SharedContext::Default,
    ): ResponseInterface {}
}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /#\[Psr\\Http\\Message\\ResponseInterface\]/);
    assert.match(reference, /Psr\\Http\\Message\\ResponseInterface \$response/);
    assert.match(reference, /Vendor\\ChildTypes\\Context \$context = Vendor\\ChildTypes\\Context::Default/);
    assert.match(reference, /@template TResponse of Psr\\Http\\Message\\ResponseInterface/);
    assert.match(reference, /@method Psr\\Http\\Message\\ResponseInterface synthesize\(Vendor\\ChildTypes\\Context \$context\) A SharedContext method description\./);
    assert.match(reference, /@param Vendor\\ChildTypes\\Context \$context A SharedContext parameter description\./);
    assert.match(reference, /@return Psr\\Http\\Message\\ResponseInterface A ResponseInterface return description\./);
    assert.match(reference, /@throws Vendor\\ChildTypes\\Failure when the external operation fails/);
    assert.doesNotMatch(reference, /UnusedInternal|Greenlight\\Internal\\Hidden/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});

test('inherited and mixin members use their declaring import aliases', async () => {
  const sourceRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-source-'));
  const documentationRoot = await mkdtemp(resolve(tmpdir(), 'greenlight-api-reference-docs-'));

  try {
    await writeFile(resolve(sourceRoot, 'ImportedParent.php'), `<?php

namespace Greenlight\\Result;

use Vendor\\ParentTypes\\{Context as SharedContext, Outcome as ParentOutcome};

class ImportedParent
{
    /**
     * @param SharedContext $context The parent SharedContext description.
     * @return ParentOutcome
     */
    public function inherited(SharedContext $context): ParentOutcome {}
}
`);
    await writeFile(resolve(sourceRoot, 'ImportedMixin.php'), `<?php

namespace Greenlight\\Doubles;

use Vendor\\MixinTypes\\Context as SharedContext;

class ImportedMixin
{
    public function mixed(SharedContext $context): self {}
}
`);
    await writeFile(resolve(sourceRoot, 'ImportedEvent.php'), `<?php

namespace Greenlight\\Event;

use Greenlight\\Doubles\\ImportedMixin as MixinAlias;
use Greenlight\\Result\\ImportedParent as ParentAlias;
use Vendor\\ChildTypes\\Context as SharedContext;

/** @mixin MixinAlias */
final class ImportedEvent extends ParentAlias
{
    public function local(SharedContext $context): void {}
}
`);

    const result = spawnSync(process.execPath, [
      script,
      `--source-root=${sourceRoot}`,
      `--documentation-root=${documentationRoot}`,
    ], { encoding: 'utf8' });

    assert.equal(result.status, 0, result.stderr);

    const reference = await readFile(resolve(documentationRoot, 'api-events.md'), 'utf8');

    assert.match(reference, /final class ImportedEvent extends Greenlight\\Result\\ImportedParent/);
    assert.match(reference, /@mixin Greenlight\\Doubles\\ImportedMixin/);
    assert.match(reference, /public function inherited\(Vendor\\ParentTypes\\Context \$context\): Vendor\\ParentTypes\\Outcome/);
    assert.match(reference, /@param Vendor\\ParentTypes\\Context \$context The parent SharedContext description\./);
    assert.match(reference, /public function mixed\(Vendor\\MixinTypes\\Context \$context\): Greenlight\\Doubles\\ImportedMixin/);
    assert.match(reference, /public function local\(Vendor\\ChildTypes\\Context \$context\): void/);
    assert.doesNotMatch(reference, /ParentAlias|MixinAlias/);
  } finally {
    await rm(sourceRoot, { recursive: true, force: true });
    await rm(documentationRoot, { recursive: true, force: true });
  }
});
