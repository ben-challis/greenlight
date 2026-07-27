<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\MetadataFactory;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\AbstractMethodTest;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\NonPublicMethodTest;
use Greenlight\Tests\Fixture\DiscoveryInvalidMethods\StaticMethodTest;

final class MetadataFactoryTest
{
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
}
