<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Reporting\Output\Output;

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
