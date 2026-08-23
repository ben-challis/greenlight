<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/** @internal */
enum OutputRouting
{
    /** Capture PHP stream writes in this process. */
    case CapturePhpStreams;

    /** Forward buffered output to worker descriptors for parent capture. */
    case ForwardToProcess;
}
