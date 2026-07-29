<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Greenlight\Runner\DefaultServices;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class AfterTestValidationRecoveryTest
{
    #[Test]
    #[DataSet('invalidReplacements')]
    public function invalidReplacementDoesNotStopLaterSubscribers(
        TestLifecycleSubscriber $invalid,
        string $message,
    ): void {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
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
        $plugins = PluginRegistry::forWorker([$invalid, $observer]);

        new Worker(DefaultServices::registry($plugins), $plugins)->run($plan, $sink);

        $result = $sink->results()[0];

        Expect::that($calls->getArrayCopy())
            ->because('invalid afterTest() replacements MUST NOT stop later subscribers')
            ->toBe(['observer:after:errored'])
            ->and($result->outcome)
            ->toBe(Outcome::Errored)
            ->and($result->error?->message)
            ->because('the original replacement error MUST remain the final diagnostic')
            ->toBe($message);
    }

    /**
     * @return iterable<string, array{TestLifecycleSubscriber, non-empty-string}>
     */
    public static function invalidReplacements(): iterable
    {
        $identityReplacement = new class implements TestLifecycleSubscriber, Fake {
            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return new TestResult(
                    new TestId('Rogue\\InjectedTest', 'wrong'),
                    $result->outcome,
                    $result->durationSeconds,
                    $result->memoryDeltaBytes,
                );
            }
        };
        $expectedId = 'Greenlight\\Tests\\Fixture\\Lifecycle\\Order\\OrderTest::theTest';

        yield 'identity replacement' => [
            $identityReplacement,
            \sprintf(
                'Plugin "%s" changed the test identity during afterTest() from "%s" to "Rogue\\InjectedTest::wrong".',
                $identityReplacement::class,
                $expectedId,
            ),
        ];

        $unattributedOutcome = new class implements TestLifecycleSubscriber, Fake {
            #[\Override]
            public function beforeTest(TestContext $context): void {}

            #[\Override]
            public function afterTest(TestContext $context, TestResult $result): TestResult
            {
                return new TestResult(
                    $result->id,
                    Outcome::Skipped,
                    $result->durationSeconds,
                    $result->memoryDeltaBytes,
                );
            }
        };

        yield 'unattributed outcome' => [
            $unattributedOutcome,
            \sprintf(
                'Plugin "%s" changed the outcome from passed to skipped without '
                . 'a new transformation-log entry from withOutcome().',
                $unattributedOutcome::class,
            ),
        ];
    }
}
