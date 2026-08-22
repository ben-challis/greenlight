<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class StagedAttachmentSanitizedNameCollisionTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
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
