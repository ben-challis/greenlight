<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\RunLifecycleSubscriber;

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
        IntegrationFixtureProvider::class,
        RunLifecycleSubscriber::class,
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
     */
    public static function fromDefinitions(array $definitions, EventSink $inner): self
    {
        return new self(self::createOwned($definitions, self::CAPABILITIES), $inner);
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
        return new self($plugins, $inner);
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
                throw new IntegrationFixtureError(\sprintf(
                    'Integration fixture provider "%s" returned %s. '
                    . 'It MUST return IntegrationFixtureDefinition instances.',
                    $provider::class,
                    \get_debug_type($definition),
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
