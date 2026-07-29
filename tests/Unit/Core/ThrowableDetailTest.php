<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class ThrowableDetailTest
{
    #[Test]
    public function rendersNormalInstanceMethodStackFrames(): void
    {
        $threw = null;

        try {
            $callLine = __LINE__ + 1;
            $this->throwFromInstanceMethod();
        } catch (\RuntimeException $exception) {
            $threw = $exception;
        }

        Expect::that(ThrowableDetail::fromThrowable($threw)->stackFrames[0])
            ->because('a throwable detail MUST identify the method and source of each stack frame')
            ->toBe(
                self::class
                . '->throwFromInstanceMethod at '
                . __FILE__
                . ':'
                . $callLine,
            );
    }

    #[Test]
    public function deepTracesAreBoundedWithATruncationMarker(): void
    {
        $capture = new class implements Fake {
            public ?\RuntimeException $exception = null;
        };

        Expect::that(function () use ($capture): void {
            try {
                $this->throwAtDepth(40);
            } catch (\RuntimeException $exception) {
                $capture->exception = $exception;

                throw $exception;
            }
        })
            ->because('the recursive helper MUST throw at its terminal depth')
            ->toThrow(\RuntimeException::class, message: 'bottom');

        $threw = $capture->exception;

        if (!$threw instanceof \RuntimeException) {
            Fail::because('Expected to capture the recursive helper exception.');
        }

        $detail = ThrowableDetail::fromThrowable($threw);

        Expect::that($detail->stackFrames)
            ->because('deep throwable traces are bounded with a truncation marker')
            ->toHaveCount(33)
            ->and($detail->stackFrames[32])
            ->toBe('... (trace truncated)');
    }

    #[Test]
    #[DataSet('invalidDetails')]
    public function rejectsInvalidConstruction(string $class, string $file, int $line, string $message): void
    {
        Expect::that(
            static fn(): ThrowableDetail => new ThrowableDetail(
                $class,
                'message',
                $file,
                $line,
            ),
        )
            ->because('a throwable detail MUST identify its type and source location')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, int, non-empty-string}>
     */
    public static function invalidDetails(): iterable
    {
        yield 'empty class' => ['', '/project/tests/PaymentTest.php', 1, 'Throwable detail class must not be empty.'];
        yield 'empty file' => [\RuntimeException::class, '', 1, 'Throwable detail file must not be empty.'];
        yield 'zero line' => [
            \RuntimeException::class,
            '/project/tests/PaymentTest.php',
            0,
            'Throwable detail line must be at least 1.',
        ];
        yield 'negative line' => [
            \RuntimeException::class,
            '/project/tests/PaymentTest.php',
            -10,
            'Throwable detail line must be at least 1.',
        ];
    }

    private function throwAtDepth(int $remaining): void
    {
        if ($remaining === 0) {
            throw new \RuntimeException('bottom');
        }

        $this->throwAtDepth($remaining - 1);
    }

    private function throwFromInstanceMethod(): never
    {
        throw new \RuntimeException('frame');
    }
}
