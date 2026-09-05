<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestId;

final readonly class AttachmentNamedPipeTest
{
    public function __construct(
        private TemporaryDirectory $directory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[Timeout(2.0)]
    public function aNamedPipeWithoutAWriterIsRejectedBeforeItIsOpened(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            throw new SkipTest('The posix extension is not available.');
        }

        $path = $this->directory->path() . '/source.pipe';

        if (!\posix_mkfifo($path, 0o600)) {
            throw new SkipTest('The filesystem did not create a named pipe.');
        }

        $root = $this->directory->path();
        $store = ArtifactStore::open(new ArtifactConfiguration($root . '/artifacts'), $root, 'named-pipe');
        $this->cleanup->defer($store->cleanup(...));
        $attachments = $store->forAttempt(new TestId(self::class, __FUNCTION__), 1, new TestArtifactBudget());

        Expect::that(static fn() => $attachments->file('evidence.bin', $path))
            ->toThrow(AttachmentError::class, message: \sprintf('Attachment source "%s" is not a regular file.', $path));
    }
}
