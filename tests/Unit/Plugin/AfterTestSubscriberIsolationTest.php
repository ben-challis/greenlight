<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

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

final readonly class AfterTestSubscriberIsolationTest
{
    #[Test]
    public function aSubscriberFailureDoesNotStopLaterSubscribers(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $broken = new readonly class ($calls) implements TestLifecycleSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                $this->calls->append('broken:after');

                throw new \RuntimeException('subscriber failed');
            }
        };
        $observer = new readonly class ($calls) implements TestLifecycleSubscriber, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                $this->calls->append('observer:after:' . $result->outcome->value);

                return $result;
            }
        };
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/Order';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $plugins = PluginRegistry::forWorker([$broken, $observer]);

        new Worker(DefaultServices::registry($plugins), $plugins)->run($plan, $sink);

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
