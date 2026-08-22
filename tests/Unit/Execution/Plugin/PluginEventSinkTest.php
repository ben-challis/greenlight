<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\RunStarted;
use Greenlight\Execution\Plugin\PluginEventSink;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Tests\Support\CollectingEventSink;

final class PluginEventSinkTest
{
    #[Test]
    public function subscribersReceiveTheExactEventBeforeTheInnerSink(): void
    {
        /** @var \ArrayObject<int, array{string, Event}> $calls */
        $calls = new \ArrayObject();
        $subscriber = (static fn(string $name): RunLifecycleSubscriber =>
            new readonly class ($calls, $name) implements RunLifecycleSubscriber, Fake {
                /**
                 * @param \ArrayObject<int, array{string, Event}> $calls
                 */
                public function __construct(
                    private \ArrayObject $calls,
                    private string $name,
                ) {}

                #[\Override]
                public function onRunEvent(Event $event): void
                {
                    $this->calls->append([$this->name, $event]);
                }
            });
        $inner = new readonly class ($calls) implements EventSink, Fake {
            /**
             * @param \ArrayObject<int, array{string, Event}> $calls
             */
            public function __construct(private \ArrayObject $calls) {}

            #[\Override]
            public function emit(Event $event): void
            {
                $this->calls->append(['inner', $event]);
            }
        };
        $sink = new PluginEventSink(
            PluginRegistry::orchestratorSide([
                $subscriber('first'),
                $subscriber('second'),
            ]),
            $inner,
        );
        $event = new RunStarted('run-1', 1, 1, 1.0);

        $sink->emit($event);

        Expect::that($calls->getArrayCopy())
            ->because('run subscribers MUST receive the exact event in order before the inner sink')
            ->toBe([
                ['first', $event],
                ['second', $event],
                ['inner', $event],
            ]);
    }

    #[Test]
    public function aSubscriberFailurePropagatesBeforeTheInnerSinkReceivesTheEvent(): void
    {
        $failure = new \RuntimeException('subscriber broke');
        $subscriber = new readonly class ($failure) implements RunLifecycleSubscriber, Fake {
            public function __construct(private \RuntimeException $failure) {}

            #[\Override]
            public function onRunEvent(Event $event): never
            {
                throw $this->failure;
            }
        };
        $inner = new CollectingEventSink();
        $sink = new PluginEventSink(
            PluginRegistry::orchestratorSide([$subscriber]),
            $inner,
        );
        $event = new RunStarted('run-1', 1, 1, 1.0);

        Expect::that(static function () use ($sink, $event): void {
            $sink->emit($event);
        })
            ->because('an orchestrator subscriber failure MUST fail event delivery')
            ->toThrow($failure);

        Expect::that($inner->events)
            ->because('the inner sink MUST not observe an event rejected by a subscriber')
            ->toBe([]);
    }
}
