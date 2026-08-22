<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Worker\DefaultServices;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class RetryDeciderOrderTest
{
    #[Test]
    public function retryDecidersStopAfterTheFirstAcceptedRetry(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $first = new readonly class ($calls) implements RetryDecider, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function shouldRetry(
                RetryPolicy $policy,
                TestResult $result,
                int $attempt,
                ?\Throwable $cause,
            ): bool {
                $this->calls->append('first:' . $attempt);

                return $attempt === 1;
            }
        };
        $second = new readonly class ($calls) implements RetryDecider, Fake {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function shouldRetry(
                RetryPolicy $policy,
                TestResult $result,
                int $attempt,
                ?\Throwable $cause,
            ): bool {
                $this->calls->append('second:' . $attempt);

                return false;
            }
        };
        $directory = FixturePath::get('RunFailingSuite');
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();
        $plugins = PluginRegistry::forWorker([$first, $second]);

        new Worker(DefaultServices::registry($plugins), $plugins)->run($plan, $sink);

        Expect::that($calls->getArrayCopy())
            ->because('retry deciders MUST stop after acceptance and continue after decline')
            ->toBe([
                'first:1',
                'first:2',
                'second:2',
            ]);
    }
}
