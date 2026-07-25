<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

/**
 * Decides whether an attachment survives a successful test attempt.
 */
enum AttachmentRetention: string
{
    case OnFailure = 'on-failure';
    case Always = 'always';
}
