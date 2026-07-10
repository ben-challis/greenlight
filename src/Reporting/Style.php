<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Text decoration shared by the human-facing reporters.
 *
 * pass(), fail() and skip() colour status fragments green, red and yellow.
 * warn() colours advisory messages yellow. duration() formats seconds and
 * escalates the colour past the slow (1s) and very slow (5s) thresholds.
 * count() pluralises a noun for its count.
 *
 * Constructed without ANSI support every method returns its text unchanged,
 * so plain and non-TTY output stays byte-clean.
 *
 * @internal
 */
final readonly class Style
{
    private const float SLOW_SECONDS = 1.0;

    private const float VERY_SLOW_SECONDS = 5.0;

    public function __construct(
        private bool $ansi,
    ) {}

    public function pass(string $text): string
    {
        return $this->paint($text, '32');
    }

    public function fail(string $text): string
    {
        return $this->paint($text, '31');
    }

    public function skip(string $text): string
    {
        return $this->paint($text, '33');
    }

    public function warn(string $text): string
    {
        return $this->paint($text, '33');
    }

    public function dim(string $text): string
    {
        return $this->paint($text, '2');
    }

    public function duration(float $seconds): string
    {
        $text = \sprintf('%.3fs', $seconds);

        if ($seconds >= self::VERY_SLOW_SECONDS) {
            return $this->fail($text);
        }

        if ($seconds >= self::SLOW_SECONDS) {
            return $this->skip($text);
        }

        return $text;
    }

    public static function count(int $count, string $noun, ?string $plural = null): string
    {
        return \sprintf('%d %s', $count, $count === 1 ? $noun : $plural ?? $noun . 's');
    }

    private function paint(string $text, string $code): string
    {
        if (!$this->ansi) {
            return $text;
        }

        return \sprintf("\x1b[%sm%s\x1b[0m", $code, $text);
    }
}
