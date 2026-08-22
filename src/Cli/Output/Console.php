<?php

declare(strict_types=1);

namespace Greenlight\Cli\Output;

use Greenlight\Reporting\Style;

/**
 * Owns CLI streams, writes, terminal capabilities, and message styles.
 *
 * @internal
 */
final readonly class Console
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     * @param \Closure(string): void $out
     * @param \Closure(string): void $err
     */
    public function __construct(private mixed $stdout, private mixed $stderr, private \Closure $out, private \Closure $err) {}

    /** @return resource */
    public function stdout(): mixed
    {
        return $this->stdout;
    }

    /** @return resource */
    public function stderr(): mixed
    {
        return $this->stderr;
    }

    public function out(string $text): void
    {
        ($this->out)($text);
    }

    public function err(string $text): void
    {
        ($this->err)($text);
    }

    public function error(string $message, bool $noAnsi): void
    {
        $this->err($this->stderrStyle($noAnsi)->error('greenlight:') . ' ' . $message . "\n");
    }

    public function capabilities(bool $noAnsi, bool $ansi = false): TerminalCapabilities
    {
        return TerminalCapabilities::detect(
            Terminal::isTty($this->stdout),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsi,
            $ansi,
        );
    }

    public function stdoutStyle(bool $noAnsi): Style
    {
        return new Style($this->capabilities($noAnsi)->color);
    }

    public function stderrStyle(bool $noAnsi): Style
    {
        return new Style(TerminalCapabilities::detect(
            Terminal::isTty($this->stderr),
            ['CI' => \getenv('CI'), 'NO_COLOR' => \getenv('NO_COLOR')],
            $noAnsi,
        )->color);
    }
}
