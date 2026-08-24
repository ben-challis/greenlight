<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\WatchSource;

/**
 * Polls the watch sources for one watch command.
 *
 * @internal
 */
final readonly class WatchSourceRuntime implements ChangeDetector
{
    /** @var list<WatchSource> */
    private array $sources;

    /**
     * @param list<PluginDefinition> $definitions
     * @param list<WatchSource> $bundledSources
     * @throws WatchSourceFailed
     */
    public static function fromDefinitions(array $definitions, array $bundledSources = []): self
    {
        $sources = $bundledSources;

        foreach ($definitions as $definition) {
            if (!$definition->supports(WatchSource::class)) {
                continue;
            }

            try {
                $source = $definition->create();
            } catch (\Throwable $failure) {
                throw WatchSourceFailed::operation($definition->pluginClass, 'creation', $failure);
            }

            if ($source instanceof WatchSource) {
                $sources[] = $source;
            }
        }

        return new self($sources);
    }

    /** @param list<WatchSource> $sources */
    public static function fromSources(array $sources): self
    {
        return new self($sources);
    }

    /** @param list<WatchSource> $sources */
    private function __construct(array $sources)
    {
        $indexed = [];

        foreach ($sources as $registration => $source) {
            $indexed[] = [
                'source' => $source,
                'priority' => $source instanceof Prioritized ? $source->priority() : 0,
                'registration' => $registration,
            ];
        }

        \usort(
            $indexed,
            static fn(array $a, array $b): int => [$a['priority'], $a['registration']]
                <=> [$b['priority'], $b['registration']],
        );

        $this->sources = \array_column($indexed, 'source');
    }

    /**
     * @return list<non-empty-string>
     * @throws WatchSourceFailed
     */
    #[\Override]
    public function poll(): array
    {
        $changes = [];

        foreach ($this->sources as $source) {
            try {
                $polled = $source->poll();
            } catch (\Throwable $failure) {
                throw WatchSourceFailed::operation($source::class, 'poll()', $failure);
            }

            foreach ($polled as $change) {
                if (!\is_string($change) || $change === '') {
                    throw WatchSourceFailed::invalidChange($source::class, $change);
                }

                $changes[$change] = true;
            }
        }

        return \array_keys($changes);
    }
}
