<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\MetadataFactory;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\WrongResourceTypeTest;

final readonly class MetadataFactoryResourceErrorTest
{
    #[Test]
    public function invalidResourceArgumentsAreWrappedWithTheirLocationAndCause(): void
    {
        $class = $this->fixtureClass();

        Expect::that(static fn(): array => new MetadataFactory()->forClass(new \ReflectionClass($class)))
            ->because('discovery MUST wrap an invalid resource attribute with its method location')
            ->toThrow(
                static function (DiscoveryError $error) use ($class): void {
                    Expect::that($error->getMessage())->toMatch(
                        '/^Attribute on '
                        . \preg_quote($class . '::neverDiscovered()', '/')
                        . ' is invalid:/',
                    );
                    Expect::that($error->getPrevious())
                        ->because('the discovery error MUST preserve the invalid resource attribute cause')
                        ->toBeInstanceOf(\TypeError::class);
                },
            );
    }

    /**
     * @return class-string
     */
    private function fixtureClass(): string
    {
        return WrongResourceTypeTest::class;
    }
}
