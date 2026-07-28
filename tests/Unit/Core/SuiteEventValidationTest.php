<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Expect\Expect;

final readonly class SuiteEventValidationTest
{
    /**
     * @param class-string<SuiteStarted|SuiteFinished> $eventClass
     */
    #[Test]
    #[DataSet('suiteEvents')]
    public function rejectsAnEmptySuiteName(string $eventClass): void
    {
        Expect::that(static fn(): SuiteStarted|SuiteFinished => new $eventClass('', 1.0))
            ->because('a suite lifecycle event MUST identify its suite')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Suite name MUST NOT be empty.',
            );
    }

    /**
     * @return iterable<string, array{class-string<SuiteStarted|SuiteFinished>}>
     */
    public static function suiteEvents(): iterable
    {
        yield 'started' => [SuiteStarted::class];

        yield 'finished' => [SuiteFinished::class];
    }
}
