<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ArtifactCleanupRootSymlinkTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataRow([false], label: 'directory target')]
    #[DataRow([true], label: 'missing target')]
    public function cleanupRemovesTheRootLinkAndPreservesItsTarget(bool $broken): void
    {
        $root = $this->tempDirectory->path();
        $target = $this->tempDirectory->subdirectory('target');
        $sentinel = $target . '/keep.txt';
        \file_put_contents($sentinel, 'keep');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root . '/output'),
            $root,
            'root-symlink',
            temporaryDirectory: $root,
        );
        $staging = $store->session()->stagingDirectory;
        \symlink($broken ? $target . '/missing' : $target, $staging);

        try {
            $store->cleanup();

            Expect::that(\is_file($sentinel))
                ->because('artifact cleanup preserves files outside its staging directory')
                ->toBeTrue();
            Expect::that(\file_get_contents($sentinel))->toBe('keep');
            Expect::that(\is_link($staging))->toBeFalse();
            $store->cleanup();
        } finally {
            if (\is_link($staging)) {
                \unlink($staging);
            }
        }
    }
}
