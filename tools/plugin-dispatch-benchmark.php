<?php

declare(strict_types=1);

namespace Greenlight\Tools;

use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\TestContext;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

require __DIR__ . '/../vendor/autoload.php';

final class DispatchBenchmarkSubscriber implements BeforeTestSubscriber, Prioritized
{
    public static int $calls = 0;

    public function __construct(private readonly int $rank) {}

    #[\Override]
    public function priority(): int
    {
        return $this->rank;
    }

    #[\Override]
    public function beforeTest(TestContext $context): void
    {
        ++self::$calls;
    }
}

$context = new TestContext(
    new \stdClass(),
    new TestId('ExampleTest', 'test'),
    new TestDefinition('ExampleTest', 'test'),
    new HarnessScopes(),
);

// Measure repeated dispatch separately from runtime construction.
foreach ([0, 1, 8] as $count) {
    $plugins = [];

    for ($index = 0; $index < $count; ++$index) {
        $plugins[] = new DispatchBenchmarkSubscriber($count - $index);
    }

    $runtime = WorkerPluginRuntime::fromPlugins($plugins);

    for ($sample = 1; $sample <= 3; ++$sample) {
        DispatchBenchmarkSubscriber::$calls = 0;
        $startedAt = \hrtime(true);

        for ($iteration = 0; $iteration < 100_000; ++$iteration) {
            $runtime->beforeTest($context);
        }

        \printf(
            "plugins=%d sample=%d iterations=100000 seconds=%.6f calls=%d\n",
            $count,
            $sample,
            (\hrtime(true) - $startedAt) / 1_000_000_000,
            DispatchBenchmarkSubscriber::$calls,
        );
    }
}
