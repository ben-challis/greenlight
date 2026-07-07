<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\PluginRegistry;

/**
 * The harness registry every worker starts from: the built-in services, the
 * Expect service carrying any configured expectation extensions, and the
 * services contributed by harness providers. A provider registering a type
 * that already exists is a configuration error and fails loudly.
 *
 * @internal
 */
final class DefaultServices
{
    private function __construct() {}

    public static function registry(PluginRegistry $plugins = new PluginRegistry()): HarnessRegistry
    {
        $extensions = $plugins->ofType(ExpectationExtension::class);

        $registry = new HarnessRegistry([
            new ServiceDefinition(Expect::class, Scope::PerTest, static fn(): Expect => new Expect($extensions)),
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles()),
        ]);

        foreach ($plugins->harnessServices() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}
