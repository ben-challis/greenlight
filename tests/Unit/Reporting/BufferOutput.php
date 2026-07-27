<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Doubles\Fake;
use Greenlight\Reporting\Output\Output;

final class BufferOutput implements Output, Fake
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
