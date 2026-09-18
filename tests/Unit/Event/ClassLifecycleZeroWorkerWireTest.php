<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;

use function Greenlight\expect;

final readonly class ClassLifecycleZeroWorkerWireTest
{
    /**
     * @param class-string<TestClassStarted|TestClassFinished> $eventClass
     */
    #[Test]
    #[DataSet('classEvents')]
    public function zeroStringWorkerIdsSurviveTheWire(string $eventClass): void
    {
        $event = $eventClass::fromWire([
            'class' => 'App\ZeroWorkerTest',
            'occurredAt' => 1.25,
            'workerId' => '0',
        ]);

        expect($event->workerId)
            ->because('class lifecycle events MUST retain non-empty zero-string worker IDs')
            ->toBe('0');
        expect($event->toWire()['workerId'])
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
