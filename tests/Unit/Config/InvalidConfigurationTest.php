<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;

use function Greenlight\expect;

final class InvalidConfigurationTest
{
    /** @param \Closure(\InvalidArgumentException): InvalidConfiguration $wrap */
    #[Test]
    #[DataSet('wrappers')]
    public function wrappedValidationFailuresPreserveTheirCause(\Closure $wrap): void
    {
        $previous = new \InvalidArgumentException('The supplied value is invalid.', 17);
        $error = $wrap($previous);

        expect($error->getMessage())->toBe($previous->getMessage());
        expect($error->getCode())->toBe(17);
        expect($error->getPrevious())->toBe($previous);
    }

    /** @return iterable<string, array{\Closure(\InvalidArgumentException): InvalidConfiguration}> */
    public static function wrappers(): iterable
    {
        yield 'resource name' => [InvalidConfiguration::invalidResourceName(...)];
        yield 'plugin factory' => [InvalidConfiguration::invalidPluginFactory(...)];
    }
}
