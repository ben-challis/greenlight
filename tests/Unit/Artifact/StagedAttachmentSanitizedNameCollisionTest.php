<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class StagedAttachmentSanitizedNameCollisionTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataSet('collidingNames')]
    public function distinctNamesThatSanitizeToTheSameStorageNameRemainDistinct(
        string $first,
        string $second,
        array $expected,
    ): void {
        $root = $this->tempDirectory->subdirectory('sanitized-name-collision');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));

        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'captures'),
            1,
            new TestArtifactBudget(),
        );
        $attachments->text($first, 'first');
        $attachments->text($second, 'second');
        $names = \array_map(
            static fn(StagedAttachment $attachment): string => \basename($attachment->storageKey),
            $attachments->seal(),
        );

        Expect::that($names)
            ->because(
                'distinct attachment names that sanitize to the same storage name '
                . 'MUST remain distinct',
            )
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function collidingNames(): iterable
    {
        yield 'space and punctuation' => [
            'evidence one.txt',
            'evidence@one.txt',
            ['01-evidence-one.txt', '02-evidence-one-2.txt'],
        ];

        yield 'Unicode and punctuation' => [
            'café.txt',
            'caf?.txt',
            ['01-caf-.txt', '02-caf--2.txt'],
        ];
    }
}
