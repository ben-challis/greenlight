<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class ArtifactCleanupIdempotenceTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function repeatedCleanupDoesNotDeleteReplacementState(): void
    {
        $root = $this->tempDirectory->subdirectory('cleanup-idempotence');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-cleanup');
        $this->cleanup->defer($store->cleanup(...));
        $staging = $store->session()->stagingDirectory;
        $replacement = $staging . '/replacement.txt';
        $this->cleanup->defer(static function () use ($replacement, $staging): void {
            @\unlink($replacement);
            @\rmdir($staging);
        });

        \mkdir($staging, 0o700, true);
        \file_put_contents($staging . '/original.txt', 'original state');

        $store->cleanup();

        Expect::that(\is_dir($staging))
            ->because('the owning store MUST remove its original staging directory')
            ->toBeFalse();

        \mkdir($staging, 0o700, true);
        \file_put_contents($replacement, 'replacement state');

        $store->cleanup();

        Expect::that(\is_file($replacement))
            ->because('a spent store MUST NOT delete replacement state at the same path')
            ->toBeTrue();

        Expect::that((string) \file_get_contents($replacement))
            ->because('replacement staging content MUST remain intact')
            ->toBe('replacement state');
    }
}
