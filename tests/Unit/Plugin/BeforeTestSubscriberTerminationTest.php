<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class BeforeTestSubscriberTerminationTest
{
    /**
     * @param 'error'|'skip' $mode
     */
    #[Test]
    #[DataSet('terminalOutcomes')]
    public function terminalOutcomesStopTheBeforeTestSubscriberChain(
        string $mode,
        Outcome $expectedOutcome,
    ): void {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $terminal = new readonly class ($calls, $mode) implements TestLifecycleSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             * @param 'error'|'skip' $mode
             */
            public function __construct(
                private \ArrayObject $calls,
                private string $mode,
            ) {}

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                $this->calls->append('terminal:' . $this->mode);

                if ($this->mode === 'skip') {
                    $context->skip('not applicable');
                }

                throw new \RuntimeException('subscriber failed');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };
        $later = new readonly class ($calls) implements TestLifecycleSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function beforeTest(TestContext $context): void
            {
                $this->calls->append('later');
            }

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return $result;
            }
        };
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/Order';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $plugins = PluginRegistry::forWorker([$terminal, $later]);

        new Worker(DefaultServices::registry($plugins), $plugins)->run($plan, $sink);

        $result = $sink->results()[0];

        Expect::that($calls->getArrayCopy())
            ->because('a terminal beforeTest() outcome MUST stop later subscribers')
            ->toBe(['terminal:' . $mode])
            ->and($result->outcome)
            ->toBe($expectedOutcome);
    }

    /**
     * @return iterable<string, array{'error'|'skip', Outcome}>
     */
    public static function terminalOutcomes(): iterable
    {
        yield 'skip' => ['skip', Outcome::Skipped];
        yield 'error' => ['error', Outcome::Errored];
    }
}
