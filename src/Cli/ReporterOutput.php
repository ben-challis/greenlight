<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\Output\Output;
use Greenlight\Reporting\Output\StreamOutput;

/**
 * Supplies one reporter destination and its terminal capabilities.
 *
 * Greenlight closes an owned file stream. It does not close standard output.
 *
 * @internal
 */
final class ReporterOutput implements Output
{
    private readonly StreamOutput $output;

    private bool $closed = false;

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        public readonly TerminalCapabilities $capabilities,
        private readonly bool $owned,
    ) {
        $this->output = new StreamOutput($stream);
    }

    #[\Override]
    public function write(string $text): void
    {
        $this->output->write($text);
    }

    public function close(): void
    {
        if (!$this->owned || $this->closed) {
            return;
        }

        $this->closed = true;
        ErrorTrap::run(fn() => \fclose($this->stream));
    }
}
