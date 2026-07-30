<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class ArtifactBuilderTest
{
    #[Test]
    public function retainsAZeroArtifactDirectory(): void
    {
        $configuration = new ArtifactBuilder()
            ->directory('0')
            ->toConfiguration();

        Expect::that($configuration->directory)
            ->because('the artifact builder MUST retain each non-empty directory')
            ->toBe('0');
    }

    /**
     * @param \Closure(ArtifactBuilder): void $configure
     */
    #[Test]
    #[DataSet('invalidAttachmentCounts')]
    public function rejectsNonPositiveAttachmentCounts(
        \Closure $configure,
        string $message,
    ): void {
        Expect::that(static fn() => $configure(new ArtifactBuilder()))
            ->because('attachment count safety limits MUST be positive')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(ArtifactBuilder): void, string}>
     */
    public static function invalidAttachmentCounts(): iterable
    {
        yield 'zero per test' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxAttachmentsPerTest(0);
            },
            'Artifact count per test must be at least 1.',
        ];

        yield 'negative per test' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxAttachmentsPerTest(-1);
            },
            'Artifact count per test must be at least 1.',
        ];

        yield 'zero per run' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxRunAttachments(0);
            },
            'Artifact count per run must be at least 1.',
        ];

        yield 'negative per run' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxRunAttachments(-1);
            },
            'Artifact count per run must be at least 1.',
        ];
    }
}
