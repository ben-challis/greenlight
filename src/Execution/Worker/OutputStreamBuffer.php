<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/** @internal */
final class OutputStreamBuffer
{
    public string $bytes = '';
    public bool $truncated = false;

    public function __construct(private readonly int $maxBytes) {}

    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $remaining = $this->maxBytes - \strlen($this->bytes);

        if ($remaining <= 0) {
            $this->truncated = true;

            return;
        }

        if (\strlen($bytes) > $remaining) {
            $this->bytes .= \substr($bytes, 0, $remaining);
            $this->truncated = true;

            return;
        }

        $this->bytes .= $bytes;
    }
}
