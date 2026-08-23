<?php

declare(strict_types=1);

namespace Greenlight\Result;

/**
 * Identifies the stream writes that Greenlight could capture for a result.
 *
 * `Buffered` contains PHP output-buffer content and diagnostics only.
 * `PhpStreams` also contains writes through the PHP `STDOUT` and `STDERR`
 * resources.
 *
 * `ProcessDescriptors` also contains safely attributed writes from child
 * processes that inherited the worker descriptors.
 */
enum OutputCaptureCapability: string
{
    case Buffered = 'buffered';
    case PhpStreams = 'php-streams';
    case ProcessDescriptors = 'process-descriptors';
}
