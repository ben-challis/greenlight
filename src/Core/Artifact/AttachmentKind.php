<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

/**
 * The representation a caller supplied for an attachment.
 */
enum AttachmentKind: string
{
    case Value = 'value';
    case Text = 'text';
    case Binary = 'binary';
    case File = 'file';
}
