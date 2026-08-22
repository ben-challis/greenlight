<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

use Greenlight\Reporting\ReportGenerationFailed;

/**
 * A destination for reporter text.
 *
 * `write()` sends the exact text to the destination. It does not add, escape,
 * or buffer text.
 */
interface Output
{
    /**
     * @throws ReportGenerationFailed when the destination cannot accept the text
     */
    public function write(string $text): void;
}
