<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class ArtifactCleanupSymlinkTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function cleanupRemovesDirectoryLinksWithoutChangingTheirTargets(): void
    {
        $root = $this->tempDirectory->subdirectory('cleanup-symlink');
        $target = $this->tempDirectory->subdirectory('cleanup-symlink-target');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-cleanup-symlink',
        );
        $this->cleanup->defer($store->cleanup(...));
        $staging = $store->session()->stagingDirectory;
        $link = $staging . '/external';
        $sentinel = $target . '/sentinel.txt';

        if (!\mkdir($staging, 0o700)) {
            Fail::because('Expected to create the artifact staging directory.');
        }

        if (\file_put_contents($sentinel, 'keep') === false) {
            Fail::because('Expected to create the target sentinel file.');
        }

        if (!\symlink($target, $link)) {
            Fail::because('Expected to create the staging directory symbolic link.');
        }

        try {
            $store->cleanup();

            Expect::that(\is_dir($staging))
                ->because('cleanup MUST remove the artifact staging directory')
                ->toBeFalse();
            Expect::that(\is_link($link))
                ->because('cleanup MUST remove symbolic links inside artifact staging')
                ->toBeFalse();
            Expect::that(\file_get_contents($sentinel))
                ->because('cleanup MUST leave symbolic link targets unchanged')
                ->toBe('keep');
        } finally {
            if (\is_link($link)) {
                \unlink($link);
            }

            if (\is_dir($staging)) {
                \rmdir($staging);
            }
        }
    }
}
