<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class AttachmentMediaTypeValidationTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataSet('mediaTypesWithControlBytes')]
    public function controlBytesInQuotedParametersAreRejected(string $mediaType): void
    {
        $root = $this->tempDirectory->subdirectory('media-type-control-bytes');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'captures'),
            1,
            $budget,
        );

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
            );
        Expect::that($attachments->collected())
            ->toBe([]);
        Expect::that($budget->attachments)
            ->because('an invalid media type MUST NOT consume the shared attachment count')
            ->toBe(0);
        Expect::that($budget->bytes)
            ->because('an invalid media type MUST NOT consume the shared byte count')
            ->toBe(0);
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
