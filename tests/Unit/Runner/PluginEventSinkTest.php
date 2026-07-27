<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Runner\PluginEventSink;
use Greenlight\Tests\Support\CollectingEventSink;

final class PluginEventSinkTest
{
    #[Test]
    public function aSubscriberFailurePropagatesBeforeTheInnerSinkReceivesTheEvent(): void
    {
        $subscriber = new class implements RunLifecycleSubscriber, Fake {
            #[\Override]
            public function onRunEvent(Event $event): never
            {
                throw new \RuntimeException('subscriber broke');
            }
        };
        $inner = new CollectingEventSink();
        $sink = new PluginEventSink(
            PluginRegistry::orchestratorSide([$subscriber]),
            $inner,
        );
        $event = new SuiteStarted('unit', 1.0);

        Expect::that(static function () use ($sink, $event): void {
            $sink->emit($event);
        })
            ->because('an orchestrator subscriber failure MUST fail event delivery')
            ->toThrow(\RuntimeException::class, message: 'subscriber broke');

        Expect::that($inner->events)
            ->because('the inner sink MUST not observe an event rejected by a subscriber')
            ->toBe([]);
    }
}
