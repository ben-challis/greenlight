<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\ProcessResult;

final class ProcessResultTest
{
    #[Test]
    public function exposesIndividualAndCombinedOutputLines(): void
    {
        $result = new ProcessResult(
            exitCode: 17,
            stdout: "first\nsecond",
            stderr: "warning\nerror",
        );

        Expect::that($result->exitCode)->because('exposes individual and combined output lines')->toBe(17);
        Expect::that($result->stdoutLines())->toBe(['first', 'second']);
        Expect::that($result->output())->toBe("first\nsecond\nwarning\nerror");
        Expect::that($result->outputLines())->toBe(['first', 'second', 'warning', 'error']);
    }

    #[Test]
    public function combinesEmptyStreamsWithoutAddingSeparators(): void
    {
        $stdoutOnly = new ProcessResult(0, 'output', '');
        $stderrOnly = new ProcessResult(1, '', 'error');
        $empty = new ProcessResult(0, '', '');

        Expect::that($stdoutOnly->output())->because('combines empty streams without adding separators')->toBe('output');
        Expect::that($stderrOnly->output())->toBe('error');
        Expect::that($empty->output())->toBe('');
        Expect::that($empty->outputLines())->toBe([]);
    }

    #[Test]
    public function lineListsRemoveOneFinalTerminatorAndPreserveBlankContent(): void
    {
        $terminated = new ProcessResult(0, "first\nsecond\n", "warning\n");
        $blankFinalLine = new ProcessResult(0, "first\n\n", '');

        Expect::that($terminated->stdoutLines())
            ->because('a final line terminator MUST NOT create a phantom output line')
            ->toBe(['first', 'second']);
        Expect::that($terminated->outputLines())
            ->toBe(['first', 'second', 'warning']);
        Expect::that($blankFinalLine->stdoutLines())
            ->because('line normalization MUST preserve intentional blank content')
            ->toBe(['first', '']);
    }
}
