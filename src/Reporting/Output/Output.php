<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

use Greenlight\Reporting\ReportingError;

/**
 * Destination for rendered reporter text.
 *
 * write() sends the text through verbatim: nothing is appended, escaped,
 * or buffered on the way to the destination.
 */
interface Output
{
    /**
     * @throws ReportingError when the destination cannot accept the text
     */
    public function write(string $text): void;
}
