<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;

use function Greenlight\expect;

final readonly class OutputCaptureChunkTest
{
    #[Test]
    public function smallWritesPreserveTheirOrderThroughTheCaptureLimit(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 65_536);
        $capture->start();

        for ($write = 0; $write < 16_384; ++$write) {
            echo 'abcd';
        }

        $output = $capture->stop();

        expect($output->stdout)->toBe(\str_repeat('abcd', 16_384));
        expect($output->stdoutTruncated)->toBeFalse();
    }

    #[Test]
    public function aUnicodeCharacterSplitAcrossWritesSurvivesTruncation(): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 5);
        $capture->start();
        echo "ab\xE2";
        echo "\x82";
        echo "\xACtail";
        $output = $capture->stop();

        expect($output->stdout)->toBe('ab€');
        expect($output->stdoutTruncated)->toBeTrue();
    }
}
