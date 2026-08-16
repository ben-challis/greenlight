<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class AttachmentNameValidationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function acceptsANameAtTheMaximumLength(): void
    {
        $root = $this->tempDirectory->subdirectory('maximum-name');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-valid-name');
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'validName'),
            1,
            $budget,
        );
        $name = \str_repeat('a', 120);

        try {
            $attachments->text($name, 'body');

            Expect::that(\array_map(
                static fn(StagedAttachment $attachment): string => $attachment->name,
                $attachments->collected(),
            ))
                ->because('an attachment name MAY contain 120 bytes')
                ->toBe([$name]);
            Expect::that($budget->attachments)
                ->because('a valid attachment MUST consume one shared attachment slot')
                ->toBe(1);
            Expect::that($budget->bytes)
                ->because('a valid attachment MUST consume its bytes from the shared budget')
                ->toBe(4);
        } finally {
            $store->cleanup();
        }
    }

    #[Test]
    #[DataSet('unsafeNames')]
    public function rejectsUnsafeNamesBeforeCreatingStagingState(string $name): void
    {
        $root = $this->tempDirectory->subdirectory('unsafe-name');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-invalid-name');
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'invalidName'),
            1,
            $budget,
        );

        try {
            Expect::that(static fn() => $attachments->text($name, 'body'))
                ->because('an unsafe attachment name MUST fail before staging')
                ->toThrow(
                    AttachmentError::class,
                    message: \sprintf(
                        'Attachment name "%s" is not a safe non-empty name.',
                        $name,
                    ),
                );
            Expect::that($attachments->collected())
                ->toBe([]);
            Expect::that($budget->attachments)
                ->because('an unsafe attachment name MUST NOT consume an attachment slot')
                ->toBe(0);
            Expect::that($budget->bytes)
                ->because('an unsafe attachment name MUST NOT consume the byte budget')
                ->toBe(0);
            Expect::that(\file_exists($store->session()->stagingDirectory))
                ->toBeFalse();
        } finally {
            $store->cleanup();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'empty' => [''];
        yield '121 bytes' => [\str_repeat('a', 121)];
        yield 'invalid UTF-8' => ["\xFF"];
        yield 'forward slash' => ['directory/name'];
        yield 'backslash' => ['directory\name'];
        yield 'control character' => ["line\nbreak"];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
    }
}
