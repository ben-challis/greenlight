<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

/**
 * Gives the run coordinator, the orchestrator, and workers one entry point to
 * attachment storage.
 *
 * open() creates the orchestrator store. It owns the staging area and has an
 * ArtifactPublisher. fromSession() creates a worker store for an existing
 * staging area. A worker store stages attachments but cannot publish them.
 * Staging goes to StagingArea, and publication and retention go to
 * ArtifactPublisher.
 *
 * @internal
 */
final class ArtifactStore
{
    private bool $cleaned = false;

    private function __construct(
        private readonly StagingArea $staging,
        private readonly ArtifactConfiguration $configuration,
        private readonly ?ArtifactPublisher $publisher,
    ) {}

    /**
     * @param (\Closure(TestResult, Attachment): bool)|null $retainAttachment
     * @throws AttachmentError
     */
    public static function open(
        ArtifactConfiguration $configuration,
        string $workingDirectory,
        string $runId,
        ?FileCopier $fileCopier = null,
        ?string $temporaryDirectory = null,
        ?\Closure $retainAttachment = null,
    ): self {
        $retention = ArtifactRetention::forConfiguration($configuration, $workingDirectory);
        $retention->validateParent();
        $output = $retention->runDirectory($runId);
        $temporaryDirectory ??= \sys_get_temp_dir();
        $staging = new StagingArea(
            new ArtifactSession(
                \rtrim($temporaryDirectory, '/') . '/greenlight-artifacts-'
                    . \substr(\hash('sha256', $runId), 0, 16)
                    . '-' . \bin2hex(\random_bytes(6)),
                $output,
            ),
            $configuration,
        );

        return new self(
            $staging,
            $configuration,
            new ArtifactPublisher($staging, $retention, $output, $fileCopier ?? new NativeFileCopier(), $retainAttachment),
        );
    }

    public static function fromSession(
        ArtifactSession $session,
        ArtifactConfiguration $configuration,
    ): self {
        return new self(new StagingArea($session, $configuration), $configuration, null);
    }

    public function session(): ArtifactSession
    {
        return $this->staging->session;
    }

    public function publicDirectory(): string
    {
        return $this->staging->session->publicDirectory;
    }

    /** @throws AttachmentError */
    public function forAttempt(TestId $id, int $attempt, TestArtifactBudget $budget): StagedAttachments
    {
        $attemptRecorded = $this->staging->hasTestDirectory($id);

        if ($attemptRecorded) {
            $this->staging->recordAttempt($id, $attempt);
        }

        return new StagedAttachments(
            $this,
            $this->configuration,
            $id,
            $attempt,
            $budget,
            $attemptRecorded,
        );
    }

    public static function testDirectory(TestId $id): string
    {
        return StagingArea::testDirectory($id);
    }

    /** @throws AttachmentError */
    public function stageBytes(
        string $bytes,
        string $name,
        string $storageKey,
        string $mediaType,
        AttachmentKind $kind,
        int $attempt,
        AttachmentRetention $retention,
        ArtifactConfiguration $configuration,
    ): StagedAttachment {
        return $this->staging->stageBytes($bytes, $name, $storageKey, $mediaType, $kind, $attempt, $retention, $configuration);
    }

    /** @throws AttachmentError */
    public function stageFile(
        string $sourcePath,
        string $name,
        string $storageKey,
        ?string $mediaType,
        int $attempt,
        AttachmentRetention $retention,
        ArtifactConfiguration $configuration,
    ): StagedAttachment {
        return $this->staging->stageFile($sourcePath, $name, $storageKey, $mediaType, $attempt, $retention, $configuration);
    }

    /** @throws AttachmentError */
    public function discard(StagedAttachment $attachment): void
    {
        $this->staging->discard($attachment);
    }

    /** @throws AttachmentError */
    public function recordAttempt(TestId $id, int $attempt): void
    {
        $this->staging->recordAttempt($id, $attempt);
    }

    /** @throws AttachmentError */
    public function publish(TestResult $result): TestResult
    {
        if ($result->attachments === []) {
            return $result;
        }

        return $this->publisher()->publish($result);
    }

    /**
     * Recovers evidence that completed atomically from a worker that stopped
     * before TestFinished.
     *
     * @throws AttachmentError
     */
    public function recover(TestResult $result): TestResult
    {
        return $this->publisher instanceof ArtifactPublisher
            ? $this->publisher->recover($result)
            : $this->publish($this->staging->recoveryCandidate($result));
    }

    public function cleanup(): void
    {
        if ($this->cleaned || !$this->publisher instanceof ArtifactPublisher) {
            return;
        }

        $this->cleaned = true;
        $this->publisher->close();
        $this->staging->cleanup();
    }

    public function complete(): ArtifactPruneReport
    {
        return $this->publisher?->complete() ?? new ArtifactPruneReport();
    }

    /** @throws AttachmentError */
    private function publisher(): ArtifactPublisher
    {
        return $this->publisher ?? throw AttachmentError::storage('A worker attempted to publish attachments');
    }
}
