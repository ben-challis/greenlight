<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;

final readonly class ArtifactCleanupIdempotenceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function repeatedCleanupDoesNotDeleteReplacementState(): void
    {
        $root = $this->tempDirectory->subdirectory('cleanup-idempotence');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-cleanup');
        $staging = $store->session()->stagingDirectory;
        $replacement = $staging . '/replacement.txt';

        try {
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
        } finally {
            @\unlink($replacement);
            @\rmdir($staging);
            $store->cleanup();
        }
    }
}
