<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

/**
 * The built-in harness services every worker registers. Plugin-contributed
 * services are layered on top of these.
 *
 * @internal
 */
final class DefaultServices
{
    private function __construct() {}

    public static function registry(): HarnessRegistry
    {
        return new HarnessRegistry([
            new ServiceDefinition(Expect::class, Scope::PerTest, static fn(): Expect => new Expect()),
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles()),
        ]);
    }
}
