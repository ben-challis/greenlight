<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Artifact\Attachment;
use Greenlight\Result\TestResult;

/** Changes the publication decision for one completed test attachment. */
interface AttachmentRetentionDecider extends Plugin
{
    public function retainAttachment(
        TestResult $result,
        Attachment $attachment,
        bool $retain,
    ): bool;
}
