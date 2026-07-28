<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\MetadataFactory;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\WrongResourceTypeTest;

final readonly class MetadataFactoryResourceErrorTest
{
    #[Test]
    public function invalidResourceArgumentsAreWrappedWithTheirLocationAndCause(): void
    {
        $class = $this->fixtureClass();
        $capture = new class {
            public ?DiscoveryError $error = null;
        };
        $attempt = static function () use ($capture, $class): array {
            try {
                return new MetadataFactory()->forClass(new \ReflectionClass($class));
            } catch (DiscoveryError $error) {
                $capture->error = $error;

                throw $error;
            }
        };

        Expect::that($attempt)
            ->because('discovery MUST wrap an invalid resource attribute with its method location')
            ->toThrow(
                DiscoveryError::class,
                matching: '/^Attribute on '
                    . \preg_quote($class . '::neverDiscovered()', '/')
                    . ' is invalid:/',
            );

        $error = $capture->error;

        if (!$error instanceof DiscoveryError) {
            Fail::because('Expected discovery to throw the captured resource attribute error.');
        }

        Expect::that($error->getPrevious())
            ->because('the discovery error MUST preserve the invalid resource attribute cause')
            ->toBeInstanceOf(\TypeError::class);
    }

    /**
     * @return class-string
     */
    private function fixtureClass(): string
    {
        return WrongResourceTypeTest::class;
    }
}
