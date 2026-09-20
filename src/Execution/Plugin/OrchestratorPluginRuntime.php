<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Artifact\Attachment;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\Plugin\AttachmentRetentionDecider;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Plugin\TestPlan;
use Greenlight\Plugin\TestPlanTransformer;
use Greenlight\Result\TestResult;

/**
 * Executes the plugin capabilities that one orchestrated run owns.
 *
 * @internal
 */
final readonly class OrchestratorPluginRuntime extends PluginRuntime implements EventSink
{
    /**
     * @var non-empty-list<class-string>
     */
    private const array CAPABILITIES = [
        AttachmentRetentionDecider::class,
        IntegrationFixtureProvider::class,
        RunLifecycleSubscriber::class,
        TestPlanTransformer::class,
    ];

    /**
     * @param list<Plugin> $plugins
     */
    private function __construct(array $plugins, private EventSink $inner)
    {
        parent::__construct($plugins);
    }

    /**
     * @param list<PluginDefinition> $definitions
     * @param list<Plugin> $bundledPlugins
     */
    public static function fromDefinitions(array $definitions, EventSink $inner, array $bundledPlugins = []): self
    {
        return new self([
            new DefaultAttachmentRetention(),
            ...$bundledPlugins,
            ...self::createOwned($definitions, self::CAPABILITIES),
        ], $inner);
    }

    /**
     * Creates a runtime from instances that Greenlight already owns.
     *
     * @internal
     *
     * @param list<Plugin> $plugins
     */
    public static function fromPlugins(array $plugins, EventSink $inner): self
    {
        return new self([new DefaultAttachmentRetention(), ...$plugins], $inner);
    }

    /** @throws PluginRuntimeError */
    public function retainAttachment(TestResult $result, Attachment $attachment): bool
    {
        $retain = true;

        foreach ($this->ordered(AttachmentRetentionDecider::class) as $decider) {
            try {
                $retain = $decider->retainAttachment($result, $attachment, $retain);
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($decider::class, 'retainAttachment', $failure);
            }
        }

        return $retain;
    }

    /**
     * @return \Generator<int, IntegrationFixtureDefinition>
     * @throws IntegrationFixtureError
     */
    public function fixtureDefinitions(): \Generator
    {
        foreach ($this->ordered(IntegrationFixtureProvider::class) as $provider) {
            try {
                $provided = $provider->integrationFixtures();
            } catch (\Throwable $failure) {
                throw IntegrationFixtureError::provider($provider::class, $failure);
            }

            foreach ($this->validatedDefinitions($provider, $provided) as $definition) {
                yield $definition;
            }
        }
    }

    /** @throws PluginRuntimeError */
    public function transformTestPlan(ExecutionPlan $plan): ExecutionPlan
    {
        $publicPlan = TestPlan::create(\array_map(
            static fn(PlanEntry $entry) => $entry->id,
            $plan->entries,
        ));

        foreach ($this->ordered(TestPlanTransformer::class) as $transformer) {
            try {
                $replacement = $transformer->transformTestPlan($publicPlan);
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($transformer::class, 'transformTestPlan', $failure);
            }

            $available = [];

            foreach ($plan->entries as $entry) {
                $available[(string) $entry->id] = $entry;
            }

            $entries = [];

            foreach ($replacement->tests as $test) {
                $entry = $available[(string) $test] ?? null;

                if ($entry === null) {
                    throw PluginRuntimeError::addedUnknownTest($transformer::class, $test);
                }

                $entries[] = $entry;
            }

            $plan = new ExecutionPlan($entries, $plan->seed);
            $publicPlan = $replacement;
        }

        return $plan;
    }

    #[\Override]
    public function emit(Event $event): void
    {
        foreach ($this->ordered(RunLifecycleSubscriber::class) as $subscriber) {
            $subscriber->onRunEvent($event);
        }

        $this->inner->emit($event);
    }

    /**
     * @param array<mixed> $provided
     *
     * @return list<IntegrationFixtureDefinition>
     * @throws IntegrationFixtureError
     */
    private function validatedDefinitions(IntegrationFixtureProvider $provider, array $provided): array
    {
        $definitions = [];

        foreach ($provided as $definition) {
            if (!$definition instanceof IntegrationFixtureDefinition) {
                throw IntegrationFixtureError::invalidDefinition($provider::class, $definition);
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
