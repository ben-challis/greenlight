<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\OrchestratorPluginRuntime;
use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\Plugin\AttachmentRetentionDecider;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\Prioritized;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class OrchestratorPluginRuntimePriorityTest
{
    #[Test]
    public function integrationFixtureProvidersKeepStablePriorityOrder(): void
    {
        $late = $this->prioritizedProvider('late', 10);
        $default = $this->provider('default');
        $samePriority = $this->prioritizedProvider('same-priority', 0);
        $early = $this->prioritizedProvider('early', -10);

        $runtime = OrchestratorPluginRuntime::fromPlugins([
            $late,
            $default,
            $samePriority,
            $early,
        ], new CollectingEventSink());
        $ids = \array_map(
            static fn(IntegrationFixtureDefinition $definition): string => $definition->id,
            [...$runtime->fixtureDefinitions()],
        );

        Expect::that($ids)
            ->because('integration fixture providers MUST keep stable plugin priority order')
            ->toBe([
                'early',
                'default',
                'same-priority',
                'late',
            ]);
    }

    #[Test]
    public function attachmentDecidersRefineTheBundledDecisionInStablePriorityOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $result = new TestResult(
            new TestId('Example\\EvidenceTest', 'passes'),
            Outcome::Passed,
            0.1,
            1,
        );
        $attachment = new Attachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            8,
            \str_repeat('a', 64),
            1,
            '/tmp/evidence.txt',
            AttachmentRetention::OnFailure,
        );
        $runtime = OrchestratorPluginRuntime::fromPlugins([
            $this->retentionDecider($events, 'late', 10, false),
            $this->retentionDecider($events, 'default', 0, true),
            $this->retentionDecider($events, 'early', -10, true),
        ], new CollectingEventSink());

        $retain = $runtime->retainAttachment($result, $attachment);

        Expect::that($events->getArrayCopy())->toBe([
            'early:discard',
            'default:retain',
            'late:retain',
        ]);
        Expect::that($retain)->toBeFalse();
    }

    #[Test]
    public function attachmentDeciderFailuresKeepThePluginAndCause(): void
    {
        $failure = new \RuntimeException('Retention decision failed');
        $decider = new readonly class ($failure) implements AttachmentRetentionDecider, Fake {
            public function __construct(private \Throwable $failure) {}

            #[\Override]
            public function retainAttachment(
                TestResult $result,
                Attachment $attachment,
                bool $retain,
            ): bool {
                throw $this->failure;
            }
        };
        $runtime = OrchestratorPluginRuntime::fromPlugins([$decider], new CollectingEventSink());
        $result = new TestResult(
            new TestId('Example\\EvidenceTest', 'passes'),
            Outcome::Passed,
            0.1,
            1,
        );
        $attachment = new Attachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            8,
            \str_repeat('a', 64),
            1,
            '/tmp/evidence.txt',
            AttachmentRetention::OnFailure,
        );
        $message = \sprintf(
            'Plugin "%s" caused an error during retainAttachment(): Retention decision failed',
            $decider::class,
        );

        Expect::that(static fn(): bool => $runtime->retainAttachment($result, $attachment))
            ->because('the runtime MUST identify the failing attachment decider')
            ->toThrow(
                static function (PluginRuntimeError $error) use ($failure, $message): void {
                    Expect::that($error->getMessage())->toBe($message);
                    Expect::that($error->getPrevious())->toBe($failure);
                },
            );
    }

    private function provider(string $id): IntegrationFixtureProvider
    {
        return new readonly class ($id) implements Fake, IntegrationFixtureProvider {
            public function __construct(private string $id) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return [new IntegrationFixtureDefinition($this->id, static function (): void {})];
            }
        };
    }

    private function prioritizedProvider(string $id, int $priority): IntegrationFixtureProvider
    {
        return new readonly class ($id, $priority) implements Fake, IntegrationFixtureProvider, Prioritized {
            public function __construct(private string $id, private int $priority) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return [new IntegrationFixtureDefinition($this->id, static function (): void {})];
            }

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }
        };
    }

    /** @param \ArrayObject<int, string> $events */
    private function retentionDecider(
        \ArrayObject $events,
        string $name,
        int $priority,
        bool $decision,
    ): AttachmentRetentionDecider {
        return new readonly class ($events, $name, $priority, $decision) implements AttachmentRetentionDecider, Fake, Prioritized {
            /** @param \ArrayObject<int, string> $events */
            public function __construct(
                private \ArrayObject $events,
                private string $name,
                private int $priority,
                private bool $decision,
            ) {}

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }

            #[\Override]
            public function retainAttachment(
                TestResult $result,
                Attachment $attachment,
                bool $retain,
            ): bool {
                $this->events->append($this->name . ':' . ($retain ? 'retain' : 'discard'));

                return $this->decision;
            }
        };
    }
}
