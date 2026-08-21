<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\MetadataFactory;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\WrongCaptureTypeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\WrongGroupTypeTest;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\AbstractMethodTest;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\NonPublicMethodTest;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\StaticMethodTest;
use Greenlight\Tests\Fixture\DiscoveryParallelConflict\ClassIsolatedTest;
use Greenlight\Tests\Fixture\DiscoveryParallelConflict\MethodIsolatedTest;

final class MetadataFactoryTest
{
    #[Test]
    public function invalidAttributeArgumentsAreWrappedWithTheirLocationAndCause(): void
    {
        $class = $this->wrongCaptureTypeClass();

        Expect::that(static fn(): array => new MetadataFactory()->forClass(new \ReflectionClass($class)))
            ->because('discovery wraps invalid attribute arguments with their location')
            ->toThrow(
                static function (DiscoveryError $error) use ($class): void {
                    Expect::that($error->getMessage())->toMatch(
                        '/^Attribute on '
                        . \preg_quote($class . '::neverDiscovered()', '/')
                        . ' is invalid:/',
                    );
                    Expect::that($error->getPrevious())
                        ->because('the discovery error preserves the invalid attribute cause')
                        ->toBeInstanceOf(\TypeError::class);
                },
            );
    }

    #[Test]
    public function invalidGroupArgumentsAreWrappedWithTheirLocationAndCause(): void
    {
        $class = $this->wrongGroupTypeClass();

        Expect::that(static fn(): array => new MetadataFactory()->forClass(new \ReflectionClass($class)))
            ->because('discovery wraps invalid group arguments with their method location')
            ->toThrow(
                static function (DiscoveryError $error) use ($class): void {
                    Expect::that($error->getMessage())->toMatch(
                        '/^Attribute on '
                        . \preg_quote($class . '::neverDiscovered()', '/')
                        . ' is invalid:/',
                    );
                    Expect::that($error->getPrevious())
                        ->because('the discovery error preserves the invalid group cause')
                        ->toBeInstanceOf(\TypeError::class);
                },
            );
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataSet('invalidMethods')]
    public function rejectsTestMethodsThatCannotRun(string $class, string $reason): void
    {
        Expect::that(
            static fn(): array => new MetadataFactory()->forClass(new \ReflectionClass($class)),
        )
            ->because('discovery MUST reject a test method that cannot run')
            ->toThrow(
                DiscoveryError::class,
                message: \sprintf(
                    'Greenlight cannot run test method %s::invalid() because %s.',
                    $class,
                    $reason,
                ),
            );
    }

    /**
     * @return iterable<string, array{class-string, non-empty-string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'non-public' => [NonPublicMethodTest::class, 'it is not public'];

        yield 'static' => [StaticMethodTest::class, 'it is static'];

        yield 'abstract' => [AbstractMethodTest::class, 'it is abstract'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataSet('parallelIsolationConflicts')]
    public function rejectsParallelClassesWithIsolation(string $class): void
    {
        Expect::that(static fn(): array => new MetadataFactory()->forClass(new \ReflectionClass($class)))
            ->because('parallel class splitting and process isolation are incompatible')
            ->toThrow(
                DiscoveryError::class,
                message: \sprintf(
                    'Test class "%s" uses incompatible attributes #[AllowParallel] and #[Isolated]. '
                    . 'Remove one of these attributes.',
                    $class,
                ),
            );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function parallelIsolationConflicts(): iterable
    {
        yield 'class isolation' => [ClassIsolatedTest::class];
        yield 'method isolation' => [MethodIsolatedTest::class];
    }

    /**
     * @return class-string
     */
    private function wrongCaptureTypeClass(): string
    {
        return WrongCaptureTypeTest::class;
    }

    /**
     * @return class-string
     */
    private function wrongGroupTypeClass(): string
    {
        return WrongGroupTypeTest::class;
    }
}
