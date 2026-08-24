<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Execution\Plugin\RunPolicyRuntime;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\RunAcceptancePolicy;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\RunPolicy;

final readonly class RunPolicyRuntimeTest
{
    #[Test]
    public function policiesReportAllFailuresInStablePriorityOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runtime = RunPolicyRuntime::fromPlugins([
            new RecordingRunPolicy($events, 'late', 10, 'late failure'),
            new RecordingRunPolicy($events, 'default', 0, 'default failure'),
            new RecordingRunPolicy($events, 'accepted', 0, null),
            new RecordingRunPolicy($events, 'early', -10, 'early failure'),
        ]);

        $messages = $runtime->failureMessages(new ResultSummary(passed: 2), 1);

        Expect::that($events->getArrayCopy())->toBe([
            'early:2:1',
            'default:2:1',
            'accepted:2:1',
            'late:2:1',
        ]);
        Expect::that($messages)->toBe([
            'early failure',
            'default failure',
            'late failure',
        ]);
    }

    #[Test]
    public function failedTestOutcomesBypassRunAcceptancePolicies(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runtime = RunPolicyRuntime::fromPlugins([
            new RecordingRunPolicy($events, 'policy', 0, 'must not run'),
        ]);

        Expect::that($runtime->failureMessages(new ResultSummary(failed: 1), 0))->toBe([]);
        Expect::that($events->getArrayCopy())->toBe([]);
    }

    #[Test]
    public function policyFailuresKeepThePluginAndCause(): void
    {
        $failure = new \RuntimeException('Policy exploded');
        $runtime = RunPolicyRuntime::fromPlugins([new FailingRunPolicy($failure)]);

        Expect::that(static fn(): array => $runtime->failureMessages(new ResultSummary(passed: 1), 0))
            ->toThrow(static function (PluginRuntimeError $error) use ($failure): void {
                Expect::that($error->getMessage())->toBe(
                    'Plugin "Greenlight\\Tests\\Unit\\Execution\\FailingRunPolicy" caused an error during failureMessage(): Policy exploded',
                );
                Expect::that($error->getPrevious())->toBe($failure);
            });
    }

    #[Test]
    public function emptyFailureMessagesAreInvalid(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runtime = RunPolicyRuntime::fromPlugins([
            new RecordingRunPolicy($events, 'empty', 0, "\n"),
        ]);

        Expect::that(static fn(): array => $runtime->failureMessages(new ResultSummary(passed: 1), 0))
            ->toThrow(
                PluginRuntimeError::class,
                message: 'Plugin "Greenlight\\Tests\\Unit\\Execution\\RecordingRunPolicy" returned an empty failure message from failureMessage().',
            );
    }

    #[Test]
    public function factoryFailuresStopPolicySetup(): void
    {
        $definition = PluginDefinition::fromFactory(
            static fn(): FailingPolicyCreation => new FailingPolicyCreation(),
        );

        Expect::that(static fn(): RunPolicyRuntime => RunPolicyRuntime::fromDefinitions(
            [$definition],
            new RunPolicy(),
        ))->toThrow(
            PluginRuntimeError::class,
            message: 'Plugin "Greenlight\\Tests\\Unit\\Execution\\FailingPolicyCreation" caused an error during creation: Policy construction exploded',
        );
    }

    #[Test]
    public function configuredRulesUseTheBundledPluginAdapter(): void
    {
        $runtime = RunPolicyRuntime::fromDefinitions(
            [],
            new RunPolicy(failOnSkipped: true, failOnRetriedPass: true),
        );

        Expect::that($runtime->failureMessages(new ResultSummary(skipped: 2), 1))->toBe([
            "Greenlight failed because the fail-on-skipped policy found 2 skipped tests.\n"
            . 'Greenlight failed because the fail-on-retried-pass policy found 1 test that passed after retry.',
        ]);
    }
}

final readonly class RecordingRunPolicy implements Fake, Prioritized, RunAcceptancePolicy
{
    /** @param \ArrayObject<int, string> $events */
    public function __construct(
        private \ArrayObject $events,
        private string $name,
        private int $priorityValue,
        private ?string $message,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priorityValue;
    }

    #[\Override]
    public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string
    {
        $this->events->append(\sprintf('%s:%d:%d', $this->name, $summary->passed, $retriedPasses));

        return $this->message;
    }
}

final readonly class FailingRunPolicy implements Fake, RunAcceptancePolicy
{
    public function __construct(private \RuntimeException $failure) {}

    #[\Override]
    public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string
    {
        throw $this->failure;
    }
}

final readonly class FailingPolicyCreation implements Fake, RunAcceptancePolicy
{
    public function __construct()
    {
        throw new \RuntimeException('Policy construction exploded');
    }

    #[\Override]
    public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string
    {
        return null;
    }
}
