<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Plugin\AttachmentRetentionDecider;
use Greenlight\Plugin\Prioritized;
use Greenlight\Result\TestResult;

/**
 * Applies the retention selected when the test created an attachment.
 *
 * @internal
 */
final readonly class DefaultAttachmentRetention implements AttachmentRetentionDecider, Prioritized
{
    #[\Override]
    public function priority(): int
    {
        return \PHP_INT_MIN;
    }

    #[\Override]
    public function retainAttachment(
        TestResult $result,
        Attachment $attachment,
        bool $retain,
    ): bool {
        $problematic = !$result->outcome->isSuccessful() || \array_any(
            $result->transformations,
            static fn($transformation): bool => !$transformation->from->isSuccessful(),
        );

        return $problematic
            || $attachment->attempt < $result->attempts
            || $attachment->retention !== AttachmentRetention::OnFailure;
    }
}
