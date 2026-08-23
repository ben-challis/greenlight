<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\DefaultServices;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class AfterTestSubscriberIsolationTest
{
    #[Test]
    public function aSubscriberFailureDoesNotStopLaterSubscribers(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $broken = new readonly class ($calls) implements AfterTestSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                $this->calls->append('broken:after');

                throw new \RuntimeException('subscriber failed');
            }
        };
        $observer = new readonly class ($calls) implements AfterTestSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                $this->calls->append('observer:after:' . $result->outcome->value);

                return $result;
            }
        };
        $directory = FixturePath::get('Lifecycle/Order');
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $plugins = WorkerPluginRuntime::fromPlugins([$observer, $broken]);

        new Worker(DefaultServices::definitions(), $plugins)->run($plan, $sink);

        Expect::that($calls->getArrayCopy())
            ->because('an afterTest() failure MUST NOT stop later subscribers')
            ->toBe([
                'broken:after',
                'observer:after:errored',
            ]);
        Expect::that($sink->results()[0]->outcome)
            ->because('the later subscriber MUST receive the errored result')
            ->toBe(Outcome::Errored);
    }
}
