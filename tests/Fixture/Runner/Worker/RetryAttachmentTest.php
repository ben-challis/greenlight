<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Worker;

use Greenlight\Artifact\Attachments;

final readonly class RetryAttachmentTest
{
    public function __construct(private Attachments $attachments) {}

    public function failsWithEvidence(): never
    {
        $this->attachments->text('failure.txt', 'retry evidence');

        throw new \RuntimeException('intentional failure');
    }
}
