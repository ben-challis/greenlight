<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class InvalidConfigurationTest
{
    #[Test]
    public function errorsRequireANamedFactory(): void
    {
        Expect::that(static fn(): object => new \ReflectionClass(InvalidConfiguration::class)->newInstance('arbitrary'))
            ->toThrow(\ReflectionException::class);
    }

    /** @param \Closure(\InvalidArgumentException): InvalidConfiguration $wrap */
    #[Test]
    #[DataSet('wrappers')]
    public function wrappedValidationFailuresPreserveTheirCause(\Closure $wrap): void
    {
        $previous = new \InvalidArgumentException('The supplied value is invalid.', 17);
        $error = $wrap($previous);

        Expect::that($error->getMessage())->toBe($previous->getMessage());
        Expect::that($error->getCode())->toBe(17);
        Expect::that($error->getPrevious())->toBe($previous);
    }

    /** @return iterable<string, array{\Closure(\InvalidArgumentException): InvalidConfiguration}> */
    public static function wrappers(): iterable
    {
        yield 'resource name' => [InvalidConfiguration::invalidResourceName(...)];
        yield 'plugin factory' => [InvalidConfiguration::invalidPluginFactory(...)];
    }
}
