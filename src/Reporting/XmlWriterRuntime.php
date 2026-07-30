<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Supplies XMLWriter availability and instances to XML reporters.
 *
 * @internal
 */
interface XmlWriterRuntime
{
    public function isAvailable(): bool;

    public function create(): \XMLWriter;
}
