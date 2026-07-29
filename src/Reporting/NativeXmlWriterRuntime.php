<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/** @internal */
final readonly class NativeXmlWriterRuntime implements XmlWriterRuntime
{
    #[\Override]
    public function isAvailable(): bool
    {
        return \class_exists(\XMLWriter::class);
    }

    #[\Override]
    public function create(): \XMLWriter
    {
        return new \XMLWriter();
    }
}
