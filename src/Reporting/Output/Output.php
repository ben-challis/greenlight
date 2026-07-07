<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

/**
 * Destination for rendered reporter text.
 *
 * Kept to a single write() method so reporters stay testable against an
 * in-memory implementation.
 */
interface Output
{
    public function write(string $text): void;
}
