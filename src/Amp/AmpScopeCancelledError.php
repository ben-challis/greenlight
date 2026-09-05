<?php

declare(strict_types=1);

namespace Greenlight\Amp;

use Greenlight\Test\OperationCancelledError;

/**
 * Indicates that an attempt scope stopped an asynchronous operation.
 *
 * @internal
 */
final class AmpScopeCancelledError extends OperationCancelledError
{
    public function __construct()
    {
        parent::__construct('The Amp attempt scope has ended.');
    }
}
