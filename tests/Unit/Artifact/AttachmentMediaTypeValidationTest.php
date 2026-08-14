<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class AttachmentMediaTypeValidationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('mediaTypesWithControlBytes')]
    public function controlBytesInQuotedParametersAreRejected(string $mediaType): void
    {
        $root = $this->tempDirectory->subdirectory('media-type-control-bytes');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'captures'),
            1,
            $budget,
        );

        try {
            Expect::that(static fn() => $attachments->text(
                'report.txt',
                'body',
                $mediaType,
            ))
                ->because('attachment media types MUST NOT contain control bytes')
                ->toThrow(
                    AttachmentError::class,
                    message: \sprintf(
                        'Attachment media type "%s" is invalid.',
                        $mediaType,
                    ),
                )
                ->and($attachments->collected())
                ->toBe([]);
            Expect::that($budget->attachments)
                ->because('an invalid media type MUST NOT consume the shared attachment count')
                ->toBe(0);
            Expect::that($budget->bytes)
                ->because('an invalid media type MUST NOT consume the shared byte count')
                ->toBe(0);
        } finally {
            $store->cleanup();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mediaTypesWithControlBytes(): iterable
    {
        yield 'null' => ["text/plain; name=\"report\x00.txt\""];
        yield 'tab' => ["text/plain; name=\"report\t.txt\""];
        yield 'line feed' => ["text/plain; name=\"report\n.txt\""];
        yield 'carriage return' => ["text/plain; name=\"report\r.txt\""];
        yield 'delete' => ["text/plain; name=\"report\x7F.txt\""];
    }
}
