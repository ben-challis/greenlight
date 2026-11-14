<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;

/**
 * Tracks whether a run retained attachments in its artifact directory.
 *
 * CI reporters announce the directory one time when the run finishes. The
 * directory() method returns null if the run has no artifact directory. It
 * also returns null if no test retained an attachment.
 *
 * @internal
 */
final class RetainedArtifacts
{
    private ?string $directory = null;

    private bool $hasAttachments = false;

    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->directory = $event->artifactsDirectory;

            return;
        }

        if ($event instanceof TestFinished && $event->result->attachments !== []) {
            $this->hasAttachments = true;
        }
    }

    public function directory(): ?string
    {
        return $this->hasAttachments ? $this->directory : null;
    }
}
