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

    #[Test]
    public function retentionIsUnboundedUntilAUserConfiguresIt(): void
    {
        $configuration = new ArtifactBuilder()->toConfiguration();

        Expect::that($configuration->maxCompletedRuns)->toBe(null);
        Expect::that($configuration->maxCompletedRunAgeSeconds)->toBe(null);
        Expect::that($configuration->maxRetainedBytes)->toBe(null);
        Expect::that($configuration->hasRetentionPolicy())->toBeFalse();
    }

    #[Test]
    public function buildsEachCompletedRunRetentionLimit(): void
    {
        $configuration = new ArtifactBuilder()
            ->maxCompletedRuns(4)
            ->maxCompletedRunAge(3_600)
            ->maxRetainedSize('2M')
            ->toConfiguration();

        Expect::that($configuration->maxCompletedRuns)->toBe(4);
        Expect::that($configuration->maxCompletedRunAgeSeconds)->toBe(3_600);
        Expect::that($configuration->maxRetainedBytes)->toBe(2 * 1024 * 1024);
        Expect::that($configuration->hasRetentionPolicy())->toBeTrue();
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
                $builder->maxAttachmentsPerTest(0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Artifact count per test must be at least 1.',
        ];

        yield 'negative per test' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxAttachmentsPerTest(-1); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Artifact count per test must be at least 1.',
        ];

        yield 'zero per run' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxRunAttachments(0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Artifact count per run must be at least 1.',
        ];

        yield 'negative per run' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxRunAttachments(-1); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Artifact count per run must be at least 1.',
        ];

        yield 'zero completed runs' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxCompletedRuns(0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Completed artifact run count must be at least 1.',
        ];

        yield 'zero completed run age' => [
            static function (ArtifactBuilder $builder): void {
                $builder->maxCompletedRunAge(0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Completed artifact run age must be at least 1 second.',
        ];
    }
}
