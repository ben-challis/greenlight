<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Expect\Expect;

final readonly class TestClassEventValidationTest
{
    /**
     * @param class-string<TestClassStarted|TestClassFinished> $eventClass
     */
    #[Test]
    #[DataSet('classEvents')]
    public function rejectsAnEmptyClassName(string $eventClass): void
    {
        Expect::that(static fn(): TestClassStarted|TestClassFinished => new $eventClass('', 1.0))
            ->because('a test-class lifecycle event MUST identify its class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Test class name cannot be empty.',
            );
    }

    /**
     * @return iterable<string, array{class-string<TestClassStarted|TestClassFinished>}>
     */
    public static function classEvents(): iterable
    {
        yield 'started' => [TestClassStarted::class];

        yield 'finished' => [TestClassFinished::class];
    }
}
