<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\WorkerProcess;

use Greenlight\Artifact\Attachments;

final readonly class WorkerAttachmentTest
{
    public function __construct(private Attachments $attachments) {}

    public function recordsEvidence(): void
    {
        $this->attachments->text('worker.txt', 'worker evidence');
    }
}
