<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\WatchSourceFailed;
use Greenlight\Cli\Watch\WatchSourceRuntime;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\WatchSource;

final readonly class WatchSourceRuntimeTest
{
    #[Test]
    public function sourcesUseStablePriorityOrderAndDeduplicateChanges(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runtime = WatchSourceRuntime::fromSources([
            new RecordingWatchSource($events, 'late', 10, ['shared', 'late']),
            new RecordingWatchSource($events, 'default', 0, ['default', 'shared']),
            new RecordingWatchSource($events, 'same-priority', 0, []),
            new RecordingWatchSource($events, 'early', -10, ['early']),
        ]);

        $changes = $runtime->poll();

        Expect::that($events->getArrayCopy())->toBe([
            'early',
            'default',
            'same-priority',
            'late',
        ]);
        Expect::that($changes)->toBe(['early', 'default', 'shared', 'late']);
    }

    #[Test]
    public function sourceFailuresKeepThePluginAndCause(): void
    {
        $failure = new \RuntimeException('Watch poll exploded');
        $runtime = WatchSourceRuntime::fromSources([
            new FailingWatchSource($failure),
        ]);

        Expect::that($runtime->poll(...))
            ->toThrow(static function (WatchSourceFailed $error) use ($failure): void {
                Expect::that($error->getMessage())->toBe(
                    'Watch source plugin "Greenlight\\Tests\\Unit\\Cli\\Watch\\FailingWatchSource" caused an error during poll(): Watch poll exploded',
                );
                Expect::that($error->getPrevious())->toBe($failure);
            });
    }
}

final readonly class RecordingWatchSource implements Fake, Prioritized, WatchSource
{
    /**
     * @param \ArrayObject<int, string> $events
     * @param list<non-empty-string> $changes
     */
    public function __construct(
        private \ArrayObject $events,
        private string $name,
        private int $priorityValue,
        private array $changes,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priorityValue;
    }

    #[\Override]
    public function poll(): array
    {
        $this->events->append($this->name);

        return $this->changes;
    }
}

final readonly class FailingWatchSource implements Fake, WatchSource
{
    public function __construct(private \RuntimeException $failure) {}

    #[\Override]
    public function poll(): array
    {
        throw $this->failure;
    }
}
