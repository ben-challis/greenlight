<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\InvalidConfiguration;

use function Greenlight\expect;

final class ArtifactBuilderTest
{
    #[Test]
    public function retainsAZeroArtifactDirectory(): void
    {
        $configuration = new ArtifactBuilder()
            ->directory('0')
            ->toConfiguration();

        expect($configuration->directory)
            ->because('the artifact builder MUST retain each non-empty directory')
            ->toBe('0');
    }

    #[Test]
    public function retentionIsUnboundedUntilAUserConfiguresIt(): void
    {
        $configuration = new ArtifactBuilder()->toConfiguration();

        expect($configuration->maxCompletedRuns)->toBe(null);
        expect($configuration->maxCompletedRunAgeSeconds)->toBe(null);
        expect($configuration->maxRetainedBytes)->toBe(null);
        expect($configuration->hasRetentionPolicy())->toBeFalse();
    }

    #[Test]
    public function buildsEachCompletedRunRetentionLimit(): void
    {
        $configuration = new ArtifactBuilder()
            ->maxCompletedRuns(4)
            ->maxCompletedRunAge(3_600)
            ->maxRetainedSize('2M')
            ->toConfiguration();

        expect($configuration->maxCompletedRuns)->toBe(4);
        expect($configuration->maxCompletedRunAgeSeconds)->toBe(3_600);
        expect($configuration->maxRetainedBytes)->toBe(2 * 1024 * 1024);
        expect($configuration->hasRetentionPolicy())->toBeTrue();
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
        expect()->calling(static fn() => $configure(new ArtifactBuilder()))
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
