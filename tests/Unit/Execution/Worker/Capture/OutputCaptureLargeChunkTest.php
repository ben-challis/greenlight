<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;
use Greenlight\Expect\Expect;

final readonly class OutputCaptureLargeChunkTest
{
    #[Test]
    #[DataSet('chunks')]
    public function aLargeWritePreservesTheNormalizedPrefix(string $prefix, string $chunk, string $expected): void
    {
        $capture = new OutputCapture(maxStdoutBytes: 12);
        $capture->start();
        echo $prefix;
        echo $chunk . \str_repeat('x', 65_536);
        $output = $capture->stop();

        Expect::that($output->stdout)->toBe($expected);
        Expect::that($output->stdoutTruncated)->toBeTrue();
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function chunks(): iterable
    {
        yield 'ASCII head' => ['head ', 'tail has more bytes', 'head tail ha'];
        yield 'complete four-byte character at the bound' => ['abcdefgh', "\u{1F600}", "abcdefgh\u{1F600}"];
        yield 'four-byte character beyond the bound' => ['abcdefghi', "\u{1F600}", 'abcdefghi'];
        yield 'four-byte character split across writes' => ["abcdefgh\xF0", "\x9F\x98\x80", "abcdefgh\u{1F600}"];
        yield 'invalid four-byte sequences contract' => ['', \str_repeat("\xF4\x90\x80\x80", 10), \str_repeat("\u{FFFD}", 4)];
        yield 'invalid bytes expand' => ['head ', \str_repeat("\xFF", 10), "head \u{FFFD}\u{FFFD}"];
    }
}
