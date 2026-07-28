<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\Ticking;

final class CompositeReporterOrderTest
{
    #[Test]
    public function everyOperationUsesReporterConstructionOrder(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $reporter = static fn(string $name): Reporter => new readonly class ($name, $calls) implements Fake, Reporter, Ticking {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(
                private string $name,
                private \ArrayObject $calls,
            ) {}

            #[\Override]
            public function onEvent(Event $event): void
            {
                $this->calls[] = $this->name . ':event:' . $event::class;
            }

            #[\Override]
            public function tick(float $now): void
            {
                $this->calls[] = \sprintf('%s:tick:%.1f', $this->name, $now);
            }

            #[\Override]
            public function finish(): void
            {
                $this->calls[] = $this->name . ':finish';
            }
        };
        $composite = new CompositeReporter([
            $reporter('first'),
            $reporter('second'),
        ]);

        $composite->onEvent(new RunStarted('run-1', 1, 2, 1.0));
        $composite->tick(1.5);
        $composite->finish();

        Expect::that($calls->getArrayCopy())
            ->because('composite operations MUST use reporter construction order')
            ->toBe([
                'first:event:' . RunStarted::class,
                'second:event:' . RunStarted::class,
                'first:tick:1.5',
                'second:tick:1.5',
                'first:finish',
                'second:finish',
            ]);
    }
}
