<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactSourceValidationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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
        $attachments = $store->forAttempt(
            new TestId('Example\AttachmentTest', 'rejectsInvalidSource'),
            1,
            new TestArtifactBudget(),
        );

        try {
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
        } finally {
            $store->cleanup();
        }
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
