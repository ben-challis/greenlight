<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\Test\TestChannel;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\PluginRegistry;

/**
 * Builds the harness registry every worker starts from.
 *
 * registry() combines the built-in services, the Expect service carrying any
 * configured expectation extensions, and the services contributed by harness
 * providers. The TestChannel service reads GREENLIGHT_CHANNEL from the
 * environment, which the orchestrator sets at spawn and the in-process
 * runner sets to 1, keeping the environment variable the single source of
 * truth for the slot.
 *
 * A provider registering a type that already exists is a configuration error
 * and fails loudly.
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
            new ServiceDefinition(TestChannel::class, Scope::PerRun, static function (): TestChannel {
                $raw = \getenv('GREENLIGHT_CHANNEL');

                return new TestChannel(\max(1, \is_string($raw) ? (int) $raw : 1));
            }),
        ]);

        foreach ($plugins->harnessServices() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}
