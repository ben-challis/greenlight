<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Internal\Filesystem\DirectoryWalkError;
use Greenlight\Internal\Filesystem\SafeDirectory;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Result\TestResult;

/**
 * Publishes retained attachments into the public run directory. Only the
 * orchestrator creates a publisher.
 *
 * The publish() method decides retention for each staged attachment. It
 * discards an attachment that it does not retain. It moves a retained
 * attachment into place and verifies its size and digest. The first
 * publication creates the run directory and its lifecycle lock. The recover()
 * method publishes the complete evidence of a crashed worker. The complete()
 * method writes the completion manifest and applies retention.
 *
 * @internal
 */
final class ArtifactPublisher
{
    private ?ArtifactRunHandle $runHandle = null;

    /**
     * @param non-empty-string $publicDirectory
     * @param (\Closure(TestResult, Attachment): bool)|null $retainAttachment
     */
    public function __construct(
        private readonly StagingArea $staging,
        private readonly ArtifactRetention $retention,
        private readonly string $publicDirectory,
        private readonly FileCopier $fileCopier,
        private readonly ?\Closure $retainAttachment,
    ) {}

    /** @throws AttachmentError */
    public function publish(TestResult $result): TestResult
    {
        if ($result->attachments === []) {
            return $result;
        }

        $published = [];

        foreach ($result->attachments as $attachment) {
            if (!$attachment instanceof StagedAttachment) {
                throw AttachmentError::storage('Attachment metadata does not contain a staging coordinate');
            }

            try {
                $retain = $this->retainAttachment instanceof \Closure
                    ? ($this->retainAttachment)($result, $attachment)
                    : $this->defaultRetention($result, $attachment);
            } catch (\Throwable $failure) {
                throw AttachmentError::plugin($failure);
            }

            if (!$retain) {
                $this->staging->discard($attachment);

                continue;
            }

            $this->publishOne($attachment);
            $published[] = $attachment->published();
        }

        return $result->withAttachments($published);
    }

    /**
     * Recovers evidence that completed atomically from a worker that stopped
     * before TestFinished.
     *
     * @throws AttachmentError
     */
    public function recover(TestResult $result): TestResult
    {
        return $this->publish($this->staging->recoveryCandidate($result));
    }

    /**
     * Completes the run metadata and applies retention. The current run is
     * not eligible for retention.
     */
    public function complete(): ArtifactPruneReport
    {
        $warnings = [];
        $protectedRunId = null;
        if ($this->runHandle instanceof ArtifactRunHandle) {
            $protectedRunId = $this->runHandle->runId;
            try {
                $this->runHandle->complete();
            } catch (\Throwable) {
                $warnings[] = 'Greenlight did not complete artifact run metadata. This run is not eligible for pruning.';
                $this->runHandle->close();
            }
        }

        // Automatic retention runs after each run. A warning for each skipped
        // directory would repeat on every run, so only the command reports them.
        $report = $this->retention->prune(protectedRunId: $protectedRunId, reportSkipped: false);

        return new ArtifactPruneReport($report->items, [...$warnings, ...$report->warnings]);
    }

    public function close(): void
    {
        $this->runHandle?->close();
    }

    /** @throws AttachmentError */
    private function publishOne(StagedAttachment $attachment): void
    {
        $storageKey = StagingArea::safeStorageKey($attachment->storageKey);
        $destination = $this->publicDirectory . '/' . $storageKey;
        if ($this->pathExists($destination)) {
            throw AttachmentError::storage('An attachment output path already exists');
        }
        $parent = \dirname($destination);
        $this->walkOutput($parent, false);
        $runHandle = $this->runHandle();

        $source = $this->staging->stagedPath($storageKey);
        $part = $destination . '.part-' . \bin2hex(\random_bytes(4));

        $this->walkOutput($parent, true);

        if ($this->pathExists($destination) || $this->pathExists($part)) {
            throw AttachmentError::storage('An attachment output path already exists');
        }

        if (ErrorTrap::run(static fn() => \filesize($source)) !== $attachment->sizeBytes) {
            throw AttachmentError::storage('Attachment staging content does not match its metadata');
        }

        try {
            $digest = $this->fileCopier->copy($source, $part);

            if (ErrorTrap::run(static fn() => \filesize($part)) !== $attachment->sizeBytes || $digest !== $attachment->sha256) {
                throw AttachmentError::storage('Attachment content does not match its metadata');
            }

            ErrorTrap::run(static fn() => \chmod($part, 0o600));

            if (!ErrorTrap::run(static fn() => \rename($part, $destination), $warning)) {
                throw AttachmentError::storage('Failed to publish attachment' . ($warning === null ? '' : ': ' . $warning));
            }
        } catch (\Throwable $error) {
            ErrorTrap::run(static fn() => \unlink($part));

            throw $error;
        }

        $runHandle->recordPublished($storageKey, $attachment->sizeBytes, $attachment->sha256);
        $this->staging->forgetPublished($storageKey);
    }

    private function defaultRetention(TestResult $result, Attachment $attachment): bool
    {
        $problematic = !$result->outcome->isSuccessful() || \array_any(
            $result->transformations,
            static fn($transformation): bool => !$transformation->from->isSuccessful(),
        );

        return $problematic
            || $attachment->attempt < $result->attempts
            || $attachment->retention !== AttachmentRetention::OnFailure;
    }

    /** @throws AttachmentError */
    private function runHandle(): ArtifactRunHandle
    {
        return $this->runHandle ??= $this->retention->begin(\basename($this->publicDirectory));
    }

    /**
     * Checks each path from $directory up to the run directory. When $create
     * is true, the method also creates each missing directory.
     *
     * @throws AttachmentError
     */
    private function walkOutput(string $directory, bool $create): void
    {
        try {
            if ($create) {
                SafeDirectory::create($directory, $this->publicDirectory);
            } else {
                SafeDirectory::check($directory, $this->publicDirectory);
            }
        } catch (DirectoryWalkError $error) {
            throw OutputDirectoryErrors::from($error);
        }
    }

    private function pathExists(string $path): bool
    {
        return \file_exists($path) || \is_link($path);
    }
}
