<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class ArtifactSourceValidationTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataSet('invalidSources')]
    public function fileAttachmentsRejectInvalidSources(
        string $sourceName,
        bool $createDirectory,
        string $reason,
    ): void {
        $root = $this->tempDirectory->subdirectory('published-' . $sourceName);
        $source = $this->tempDirectory->path() . '/' . $sourceName;

        if ($createDirectory) {
            \mkdir($source);
        }

        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-source-validation');
        $this->cleanup->defer($store->cleanup(...));
        $attachments = $store->forAttempt(
            new TestId('Example\AttachmentTest', 'rejectsInvalidSource'),
            1,
            new TestArtifactBudget(),
        );

        $warning = null;
        Expect::that(static function () use ($attachments, $source, &$warning): void {
            ErrorTrap::run(
                static fn() => $attachments->file('evidence.txt', $source),
                $warning,
            );
        })
            ->because('file attachments reject invalid sources')
            ->toThrow(
                AttachmentError::class,
                message: \sprintf('Attachment source "%s" %s.', $source, $reason),
            );
        Expect::that($warning)
            ->because('an invalid attachment source MUST not leak an engine diagnostic')
            ->toBeNull();
        Expect::that($attachments->collected())
            ->because('an invalid source does not create an attachment')
            ->toBe([]);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function invalidSources(): iterable
    {
        yield 'missing file' => [
            'missing-source',
            false,
            'is not a readable regular file',
        ];

        yield 'directory' => [
            'directory-source',
            true,
            'is not a regular file',
        ];
    }
}
