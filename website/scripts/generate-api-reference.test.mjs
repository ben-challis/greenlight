import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const script = resolve(dirname(fileURLToPath(import.meta.url)), 'generate-api-reference.mjs');

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
