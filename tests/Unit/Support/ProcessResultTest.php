<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\ProcessResult;

use function Greenlight\expect;

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

        expect($result->exitCode)->because('exposes individual and combined output lines')->toBe(17);
        expect($result->stdoutLines())->toBe(['first', 'second']);
        expect($result->output())->toBe("first\nsecond\nwarning\nerror");
        expect($result->outputLines())->toBe(['first', 'second', 'warning', 'error']);
    }

    #[Test]
    public function combinesEmptyStreamsWithoutAddingSeparators(): void
    {
        $stdoutOnly = new ProcessResult(0, 'output', '');
        $stderrOnly = new ProcessResult(1, '', 'error');
        $empty = new ProcessResult(0, '', '');

        expect($stdoutOnly->output())->because('combines empty streams without adding separators')->toBe('output');
        expect($stderrOnly->output())->toBe('error');
        expect($empty->output())->toBe('');
        expect($empty->outputLines())->toBe([]);
    }

    #[Test]
    public function lineListsRemoveOneFinalTerminatorAndPreserveBlankContent(): void
    {
        $terminated = new ProcessResult(0, "first\nsecond\n", "warning\n");
        $blankFinalLine = new ProcessResult(0, "first\n\n", '');

        expect($terminated->stdoutLines())
            ->because('a final line terminator MUST NOT create a phantom output line')
            ->toBe(['first', 'second']);
        expect($terminated->outputLines())
            ->toBe(['first', 'second', 'warning']);
        expect($blankFinalLine->stdoutLines())
            ->because('line normalization MUST preserve intentional blank content')
            ->toBe(['first', '']);
    }
}
