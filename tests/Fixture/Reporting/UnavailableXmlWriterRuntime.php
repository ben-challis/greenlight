<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Reporting;

use Greenlight\Doubles\Fake;
use Greenlight\Reporting\XmlWriterRuntime;

final readonly class UnavailableXmlWriterRuntime implements Fake, XmlWriterRuntime
{
    #[\Override]
    public function isAvailable(): bool
    {
        return false;
    }

    #[\Override]
    public function create(): \XMLWriter
    {
        throw new \RuntimeException('The unavailable XMLWriter runtime MUST NOT create a writer.');
    }
}
