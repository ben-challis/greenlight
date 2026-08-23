<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Execution\Worker\StandardHarnessPlugin;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class SandboxLifecycleTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    #[DataSet('initialEnvironmentValues')]
    public function defaultSandboxesAreInjectedAndCleanedUpAfterTheTest(?string $initialValue): void
    {
        TraceLog::drain();

        if ($initialValue === null) {
            $this->environment->unset('GREENLIGHT_SANDBOX_E2E');
        } else {
            $this->environment->set('GREENLIGHT_SANDBOX_E2E', $initialValue);
        }

        $directory = FixturePath::get('Lifecycle/HarnessSandboxes');
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker(new StandardHarnessPlugin()->services())
            ->run($plan, $sink);

        $tempPath = null;

        foreach (TraceLog::drain() as $entry) {
            if (\str_starts_with($entry, 'temp:')) {
                $tempPath = \substr($entry, 5);
            }
        }

        Expect::that($outcome->summary->passed)->toBe(1);
        Expect::that($tempPath)->not()->toBeNull();
        Expect::that(\file_exists($tempPath))->toBeFalse();
        Expect::that(\getenv('GREENLIGHT_SANDBOX_E2E'))->toBe($initialValue ?? false);
        Expect::that($this->superglobalValue($_ENV, 'GREENLIGHT_SANDBOX_E2E'))->toBe($initialValue);
        Expect::that($this->superglobalValue($_SERVER, 'GREENLIGHT_SANDBOX_E2E'))->toBe($initialValue);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function initialEnvironmentValues(): iterable
    {
        yield 'present initial state' => ['outside'];
        yield 'absent initial state' => [null];
    }

    /**
     * The sandbox changes superglobals in a way that the analyzer cannot detect.
     * Read through a parameter so the analyzer does not assume a fixed offset.
     *
     * @param array<mixed> $superglobal
     */
    private function superglobalValue(array $superglobal, string $name): mixed
    {
        return $superglobal[$name] ?? null;
    }
}
