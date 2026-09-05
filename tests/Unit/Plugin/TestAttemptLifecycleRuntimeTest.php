<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestAttemptLifecycle;

final class TestAttemptLifecycleRuntimeTest
{
    #[Test]
    public function lifecycleEntryUsesPriorityAndExitReversesTheOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $early = new LifecycleRuntimeProbe($events, 'early', -10);
        $late = new LifecycleRuntimeProbe($events, 'late', 10);
        $runtime = WorkerPluginRuntime::fromDefinitions([
            PluginDefinition::fromFactory(static fn(): LifecycleRuntimeProbe => $late),
            PluginDefinition::fromFactory(static fn(): LifecycleRuntimeProbe => $early),
        ]);

        [$entered, $failure] = $runtime->enterTestAttempt(12.5);
        $bodyFailures = $runtime->leaveTestBody($entered);
        $releaseFailures = $runtime->leaveTestAttempt($entered);

        Expect::that($failure)->toBeNull();
        Expect::that($bodyFailures)->toBe([]);
        Expect::that($releaseFailures)->toBe([]);
        Expect::that($early->deadline)->toBe(12.5);
        Expect::that($late->deadline)->toBe(12.5);
        Expect::that($events->getArrayCopy())->toBe([
            'early:enter', 'late:enter', 'late:body', 'early:body', 'late:release', 'early:release',
        ]);
    }

    #[Test]
    public function failedEntryReleasesOnlySuccessfulEntries(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $early = new LifecycleRuntimeProbe($events, 'early', -10);
        $failed = new LifecycleRuntimeProbe($events, 'failed', 0, 'enter');
        $late = new LifecycleRuntimeProbe($events, 'late', 10);
        $runtime = WorkerPluginRuntime::fromPlugins([$early, $failed, $late]);

        [$entered, $failure] = $runtime->enterTestAttempt(null);
        $runtime->leaveTestBody($entered);
        $runtime->leaveTestAttempt($entered);

        Expect::that($failure?->getMessage())->toBe('Lifecycle operation failed.');
        Expect::that($events->getArrayCopy())->toBe(['early:enter', 'failed:enter', 'early:body', 'early:release']);
    }

    #[Test]
    public function failedExitDoesNotPreventOtherLifecyclesFromExiting(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $early = new LifecycleRuntimeProbe($events, 'early', -10);
        $late = new LifecycleRuntimeProbe($events, 'late', 10, 'body');
        $runtime = WorkerPluginRuntime::fromPlugins([$early, $late]);

        [$entered] = $runtime->enterTestAttempt(null);
        $failures = $runtime->leaveTestBody($entered);
        $runtime->leaveTestAttempt($entered);

        Expect::that($failures)->toHaveCount(1);
        Expect::that($events->getArrayCopy())->toBe([
            'early:enter', 'late:enter', 'late:body', 'early:body', 'late:release', 'early:release',
        ]);
    }
}

final class LifecycleRuntimeProbe implements Fake, Prioritized, TestAttemptLifecycle
{
    public ?float $deadline = null;

    /** @param \ArrayObject<int, string> $events */
    public function __construct(
        private readonly \ArrayObject $events,
        private readonly string $name,
        private readonly int $priority,
        private readonly ?string $failAt = null,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priority;
    }

    #[\Override]
    public function enterTestAttempt(?float $deadline): void
    {
        $this->deadline = $deadline;
        $this->record('enter');
    }

    #[\Override]
    public function leaveTestBody(): void
    {
        $this->record('body');
    }

    #[\Override]
    public function leaveTestAttempt(): void
    {
        $this->record('release');
    }

    private function record(string $stage): void
    {
        $this->events->append($this->name . ':' . $stage);

        if ($stage === $this->failAt) {
            throw new \RuntimeException('Lifecycle operation failed.');
        }
    }
}
