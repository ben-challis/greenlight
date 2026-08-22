<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

/**
 * The form of attachment content that a caller supplies.
 */
enum AttachmentKind: string
{
    case Value = 'value';
    case Text = 'text';
    case Binary = 'binary';
    case File = 'file';
}
