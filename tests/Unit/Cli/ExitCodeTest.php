<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Output\ExitCode;
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
    #[DataSet('dynamicProcessCodes')]
    public function preservesDynamicProcessCodes(int $processCode): void
    {
        Expect::that(ExitCode::fromInt($processCode)->toInt())->toBe($processCode);
    }

    /** @return iterable<string, array{int}> */
    public static function dynamicProcessCodes(): iterable
    {
        yield 'interrupt signal' => [128 + \SIGINT];
        yield 'quit signal' => [128 + \SIGQUIT];
        yield 'termination signal' => [128 + \SIGTERM];
    }
}
