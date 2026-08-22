<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\StorageBuilder;
use Greenlight\Expect\Expect;

final readonly class StorageBuilderTest
{
    #[Test]
    public function repeatedConfigurationKeepsAllAreaDirectories(): void
    {
        $configuration = GreenlightConfig::create()
            ->storage(static fn(StorageBuilder $storage) => $storage
                ->rootDirectory('build/greenlight')
                ->stateDirectory('/shared/state'))
            ->storage(static fn(StorageBuilder $storage) => $storage
                ->cacheDirectory('/local/cache')
                ->generatedCodeDirectory('/local/code')
                ->temporaryDirectory('/local/tmp'))
            ->build()
            ->storage;

        Expect::that($configuration->rootDirectory)->toBe('build/greenlight');
        Expect::that($configuration->stateDirectory)->toBe('/shared/state');
        Expect::that($configuration->cacheDirectory)->toBe('/local/cache');
        Expect::that($configuration->generatedCodeDirectory)->toBe('/local/code');
        Expect::that($configuration->temporaryDirectory)->toBe('/local/tmp');
    }

    /** @param \Closure(StorageBuilder): mixed $configure */
    #[Test]
    #[DataSet('invalidDirectories')]
    public function invalidDirectoriesGiveExactGuidance(
        \Closure $configure,
        string $message,
    ): void {
        Expect::that(static fn(): mixed => $configure(new StorageBuilder()))
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(StorageBuilder): mixed, non-empty-string}>
     */
    public static function invalidDirectories(): iterable
    {
        yield 'empty root' => [
            static fn(StorageBuilder $storage): StorageBuilder => $storage->rootDirectory(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            'Storage root directory cannot be empty.',
        ];
        yield 'state null byte' => [
            static fn(StorageBuilder $storage): StorageBuilder => $storage->stateDirectory("state\0dir"),
            'State directory cannot contain a null byte.',
        ];
        yield 'empty cache' => [
            static fn(StorageBuilder $storage): StorageBuilder => $storage->cacheDirectory(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            'Cache directory cannot be empty.',
        ];
        yield 'generated code null byte' => [
            static fn(StorageBuilder $storage): StorageBuilder => $storage->generatedCodeDirectory("code\0dir"),
            'Generated-code directory cannot contain a null byte.',
        ];
        yield 'empty temporary' => [
            static fn(StorageBuilder $storage): StorageBuilder => $storage->temporaryDirectory(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            'Temporary directory cannot be empty.',
        ];
    }
}
