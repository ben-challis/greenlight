<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Support\CollectingEventSink;

final class FixtureLifecycleTest
{
    #[Test]
    public function defaultFixturesAreInjectedAndCleanedUpAfterTheTest(): void
    {
        TraceLog::drain();
        \putenv('GREENLIGHT_FIXTURE_E2E=outside');
        $_ENV['GREENLIGHT_FIXTURE_E2E'] = 'outside';
        $_SERVER['GREENLIGHT_FIXTURE_E2E'] = 'outside';

        try {
            $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/HarnessFixtures';
            $plan = new TestDiscoverer()->discover([$directory]);
            $sink = new CollectingEventSink();

            $outcome = new Worker(DefaultServices::registry(), PluginRegistry::forWorker([]))
                ->run($plan, $sink);

            $tempPath = null;

            foreach (TraceLog::drain() as $entry) {
                if (\str_starts_with($entry, 'temp:')) {
                    $tempPath = \substr($entry, 5);
                }
            }

            Expect::that($outcome->summary->passed)->toBe(1)
                ->and($tempPath)->not()->toBeNull()
                ->and(\file_exists((string) $tempPath))->toBeFalse()
                ->and(\getenv('GREENLIGHT_FIXTURE_E2E'))->toBe('outside')
                ->and($this->superglobalValue($_ENV, 'GREENLIGHT_FIXTURE_E2E'))->toBe('outside')
                ->and($this->superglobalValue($_SERVER, 'GREENLIGHT_FIXTURE_E2E'))->toBe('outside');
        } finally {
            \putenv('GREENLIGHT_FIXTURE_E2E');
            unset($_ENV['GREENLIGHT_FIXTURE_E2E'], $_SERVER['GREENLIGHT_FIXTURE_E2E']);
        }
    }

    /**
     * Reads through parameters so static analysis cannot narrow the offset:
     * the sandbox under test mutates the superglobals behind the analyser's
     * back.
     *
     * @param array<mixed> $superglobal
     */
    private function superglobalValue(array $superglobal, string $name): mixed
    {
        return $superglobal[$name] ?? null;
    }
}
