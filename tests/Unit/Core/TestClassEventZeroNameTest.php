<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Expect\Expect;

final readonly class TestClassEventZeroNameTest
{
    /**
     * @param class-string<TestClassStarted|TestClassFinished> $eventClass
     */
    #[Test]
    #[DataSet('classEvents')]
    public function zeroStringClassNamesSurviveTheWire(string $eventClass): void
    {
        $event = new $eventClass('0', 1.25, 'worker-1');
        $restored = $eventClass::fromWire($event->toWire());

        Expect::that($event->class)
            ->because('class lifecycle events MUST retain non-empty zero-string class names')
            ->toBe('0')
            ->and($restored->class)
            ->toBe('0');
    }

    /**
     * @return iterable<string, array{class-string<TestClassStarted|TestClassFinished>}>
     */
    public static function classEvents(): iterable
    {
        yield 'class started' => [TestClassStarted::class];
        yield 'class finished' => [TestClassFinished::class];
    }
}
