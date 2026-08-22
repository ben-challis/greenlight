<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

/**
 * Determines if Greenlight retains an attachment from a successful test attempt.
 */
enum AttachmentRetention: string
{
    case OnFailure = 'on-failure';
    case Always = 'always';
}
