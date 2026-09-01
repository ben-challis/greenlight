<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\ExitCode;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\CommandResult;

final readonly class ExitCodeTest
{
    #[Test]
    #[DataSet('results')]
    public function convertsACommandResult(CommandResult $result, int $value): void
    {
        Expect::that(ExitCode::fromCommandResult($result)->value())->toBe($value);
    }

    /** @return iterable<string, array{CommandResult, int}> */
    public static function results(): iterable
    {
        yield 'success' => [CommandResult::success(), 0];
        yield 'failure' => [CommandResult::failure(), 1];
        yield 'usage error' => [CommandResult::usage(), 64];
        yield 'interruption' => [CommandResult::interrupted(\SIGTERM), 128 + \SIGTERM];
    }
}
