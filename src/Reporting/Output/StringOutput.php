<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

/**
 * Collects reporter text in memory.
 *
 * @internal
 */
final class StringOutput implements Output
{
    private string $contents = '';

    #[\Override]
    public function write(string $text): void
    {
        $this->contents .= $text;
    }

    public function contents(): string
    {
        return $this->contents;
    }
}
