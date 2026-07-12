<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Text decoration shared by the human-facing reporters.
 *
 * ok(), warn() and error() colour text green, yellow and red by severity;
 * callers map their domain onto a severity, so a passed count renders ok
 * and a skipped count renders warn. dim() de-emphasises secondary text.
 * duration() formats seconds and escalates from plain through warn (1s) to
 * error (5s).
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

    public function __construct(private bool $ansi) {}

    public function ok(string $text): string
    {
        return $this->paint($text, '32');
    }

    public function warn(string $text): string
    {
        return $this->paint($text, '33');
    }

    public function error(string $text): string
    {
        return $this->paint($text, '31');
    }

    public function dim(string $text): string
    {
        return $this->paint($text, '2');
    }

    public function duration(float $seconds): string
    {
        $text = \sprintf('%.3fs', $seconds);

        if ($seconds >= self::VERY_SLOW_SECONDS) {
            return $this->error($text);
        }

        if ($seconds >= self::SLOW_SECONDS) {
            return $this->warn($text);
        }

        return $text;
    }

    private function paint(string $text, string $code): string
    {
        if (!$this->ansi) {
            return $text;
        }

        return \sprintf("\x1b[%sm%s\x1b[0m", $code, $text);
    }
}
