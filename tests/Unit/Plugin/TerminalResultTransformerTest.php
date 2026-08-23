<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\StandardHarnessPlugin;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\TerminalResultTransformer;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;

final readonly class TerminalResultTransformerTest
{
    #[Test]
    public function transformerRunsOnceAfterTheLastAttempt(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $plugin = new readonly class ($calls) implements Fake, RetryDecider, TerminalResultTransformer {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function shouldRetry(
                RetryPolicy $policy,
                TestResult $result,
                int $attempt,
                ?\Throwable $cause,
            ): bool {
                return $result->outcome === Outcome::Errored && $attempt === 1;
            }

            #[\Override]
            public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult
            {
                $this->calls->append(\sprintf(
                    '%s:%d:%s',
                    $definition->method,
                    $result->attempts,
                    $result->outcome->value,
                ));

                return $result->outcome === Outcome::Errored
                    ? $result->withOutcome(Outcome::Skipped, self::class)
                    : $result;
            }
        };
        $plan = new TestDiscoverer()->discover([FixturePath::get('RunFailingSuite')]);
        $sink = new CollectingEventSink();
        $runtime = WorkerPluginRuntime::fromPlugins([$plugin]);

        new Worker(new StandardHarnessPlugin()->services(), $runtime)->run($plan, $sink);

        Expect::that($calls->getArrayCopy())
            ->because('a terminal transformer MUST receive only the result that remains after retries')
            ->toBe([
                'passes:1:passed',
                'explodes:2:errored',
            ]);
        Expect::that($sink->results()[1]->outcome)->toBe(Outcome::Skipped);
        Expect::that($sink->results()[1]->transformations)->toHaveCount(1);
    }

    #[Test]
    public function transformerFailureDoesNotStopLaterTransformers(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $broken = new readonly class ($calls) implements Fake, TerminalResultTransformer {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult
            {
                $this->calls->append('broken');

                throw new \RuntimeException('terminal policy failed');
            }
        };
        $observer = new readonly class ($calls) implements Fake, TerminalResultTransformer {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult
            {
                $this->calls->append('observer:' . $result->outcome->value);

                return $result;
            }
        };
        $definition = new TestDefinition(self::class, __FUNCTION__);
        $result = new TestResult(new TestId(self::class, __FUNCTION__), Outcome::Passed, 0.0, 0);

        $transformed = WorkerPluginRuntime::fromPlugins([$broken, $observer])
            ->terminalResult($definition, $result);

        Expect::that($calls->getArrayCopy())->toBe(['broken', 'observer:errored']);
        Expect::that($transformed->outcome)->toBe(Outcome::Errored);
        Expect::that($transformed->error?->class)->toBe(PluginRuntimeError::class);
        Expect::that($transformed->error?->message)
            ->toContain('caused an error during transformTerminalResult(): terminal policy failed');
    }

    #[Test]
    public function transformerCannotReplaceTheTestIdentity(): void
    {
        $rogue = new class implements Fake, TerminalResultTransformer {
            #[\Override]
            public function transformTerminalResult(TestDefinition $definition, TestResult $result): TestResult
            {
                return new TestResult(new TestId('Rogue\\ReplacementTest', 'replacement'), Outcome::Passed, 0.0, 0);
            }
        };
        $definition = new TestDefinition(self::class, __FUNCTION__);
        $result = new TestResult(new TestId(self::class, __FUNCTION__), Outcome::Passed, 0.0, 0);

        $transformed = WorkerPluginRuntime::fromPlugins([$rogue])->terminalResult($definition, $result);

        Expect::that($transformed->id)->toEqual($result->id);
        Expect::that($transformed->outcome)->toBe(Outcome::Errored);
        Expect::that($transformed->error?->message)
            ->toContain('changed the test identity during transformTerminalResult()');
    }
}
