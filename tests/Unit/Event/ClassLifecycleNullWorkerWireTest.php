<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;

final readonly class ClassLifecycleNullWorkerWireTest
{
    /**
     * @param class-string<TestClassStarted|TestClassFinished> $eventClass
     */
    #[Test]
    #[DataSet('classLifecycleEvents')]
    public function explicitNullWorkerIdIsRejected(string $eventClass): void
    {
        $payload = [
            'class' => 'App\ExampleTest',
            'occurredAt' => 1_780_000_000.5,
            'workerId' => null,
        ];

        Expect::that(static fn(): TestClassStarted|TestClassFinished => $eventClass::fromWire($payload))
            ->because('class lifecycle worker IDs MUST distinguish explicit null from a missing legacy field')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "workerId" must be a string, got null.',
            );
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
