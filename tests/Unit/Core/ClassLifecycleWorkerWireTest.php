<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Expect\Expect;

final readonly class ClassLifecycleWorkerWireTest
{
    /**
     * @param class-string<TestClassStarted|TestClassFinished> $eventClass
     */
    #[Test]
    #[DataSet('classLifecycleEvents')]
    public function workerAttributionSurvivesTheWire(string $eventClass): void
    {
        $payload = [
            'class' => 'App\ExampleTest',
            'occurredAt' => 1_780_000_000.5,
            'workerId' => 'worker-2',
        ];

        $event = $eventClass::fromWire($payload);

        Expect::that($event->workerId)
            ->because('class lifecycle events MUST preserve their worker attribution')
            ->toBe('worker-2')
            ->and($event->toWire())
            ->toBe($payload);
    }

    /**
     * @return iterable<string, array{class-string<TestClassStarted|TestClassFinished>}>
     */
    public static function classLifecycleEvents(): iterable
    {
        yield 'class started' => [TestClassStarted::class];
        yield 'class finished' => [TestClassFinished::class];
    }
}
