<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
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
                message: 'Test class name MUST NOT be empty.',
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
