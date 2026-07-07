<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Reporting\Output\Output;

/**
 * In-memory Output for reporter tests: accumulates every write into a string.
 */
final class BufferOutput implements Output
{
    private string $buffer = '';

    #[\Override]
    public function write(string $text): void
    {
        $this->buffer .= $text;
    }

    public function buffer(): string
    {
        return $this->buffer;
    }
}
