<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Command;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Command\ExitCode;
use Greenlight\Expect\Expect;

final readonly class ExitCodeTest
{
    #[Test]
    #[DataSet('processCodes')]
    public function convertsNamedCommandResultsAtTheProcessSeam(ExitCode $exitCode, int $processCode, bool $successful): void
    {
        Expect::that($exitCode->toInt())->toBe($processCode);
        Expect::that($exitCode->isSuccess())->toBe($successful);
    }

    /** @return iterable<string, array{ExitCode, int, bool}> */
    public static function processCodes(): iterable
    {
        yield 'success' => [ExitCode::success(), 0, true];
        yield 'failure' => [ExitCode::failure(), 1, false];
        yield 'usage' => [ExitCode::usage(), 64, false];
    }

    #[Test]
    #[DataSet('signals')]
    public function convertsSignalsToProcessCodes(int $signal): void
    {
        Expect::that(ExitCode::signal($signal)->toInt())->toBe(128 + $signal);
    }

    /** @return iterable<string, array{int}> */
    public static function signals(): iterable
    {
        yield 'interrupt signal' => [\SIGINT];
        yield 'quit signal' => [\SIGQUIT];
        yield 'termination signal' => [\SIGTERM];
    }

    #[Test]
    #[DataSet('invalidSignals')]
    public function rejectsAnInvalidSignal(int $signal): void
    {
        Expect::that(static fn(): ExitCode => ExitCode::signal($signal))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Signal number MUST be from 1 through 127.',
            );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidSignals(): iterable
    {
        yield 'zero' => [0];
        yield 'above 127' => [128];
    }

    #[Test]
    public function preservesAPluginProcessCode(): void
    {
        Expect::that(ExitCode::fromInt(7)->toInt())->toBe(7);
    }

    #[Test]
    #[DataSet('invalidProcessCodes')]
    public function rejectsAnInvalidProcessCode(int $processCode): void
    {
        Expect::that(static fn(): ExitCode => ExitCode::fromInt($processCode))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Exit code MUST be from 0 through 255.',
            );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidProcessCodes(): iterable
    {
        yield 'negative' => [-1];
        yield 'above 255' => [256];
    }
}
