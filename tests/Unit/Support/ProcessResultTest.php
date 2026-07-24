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

        Expect::that($result->exitCode)->toBe(17)
            ->and($result->stdoutLines())->toBe(['first', 'second'])
            ->and($result->stderrLines())->toBe(['warning', 'error'])
            ->and($result->output())->toBe("first\nsecond\nwarning\nerror")
            ->and($result->outputLines())->toBe(['first', 'second', 'warning', 'error']);
    }

    #[Test]
    public function combinesEmptyStreamsWithoutAddingSeparators(): void
    {
        $stdoutOnly = new ProcessResult(0, 'output', '');
        $stderrOnly = new ProcessResult(1, '', 'error');
        $empty = new ProcessResult(0, '', '');

        Expect::that($stdoutOnly->output())->toBe('output')
            ->and($stderrOnly->output())->toBe('error')
            ->and($empty->output())->toBe('')
            ->and($empty->outputLines())->toBe([]);
    }
}
