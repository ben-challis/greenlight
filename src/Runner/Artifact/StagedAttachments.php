<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\Attachments;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Test\TestId;

/**
 * Per-attempt attachment collector backed by the run's private staging store.
 *
 * @internal
 */
final class StagedAttachments implements Attachments
{
    /**
     * @var list<StagedAttachment>
     */
    private array $attachments = [];

    /**
     * @var array<string, positive-int>
     */
    private array $names = [];

    private bool $sealed = false;

    public function __construct(
        private readonly ArtifactStore $store,
        private readonly ArtifactConfiguration $configuration,
        private readonly TestId $testId,
        private readonly int $attempt,
        private readonly TestArtifactBudget $budget,
        private bool $attemptRecorded = false,
    ) {}

    #[\Override]
    public function value(
        string $name,
        mixed $value,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        try {
            $encoded = \json_encode(
                $value,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                64,
            );
        } catch (\JsonException $error) {
            throw AttachmentError::invalidValue($error->getMessage());
        }

        $this->stage($name, $encoded . "\n", 'application/json', AttachmentKind::Value, $retention);
    }

    #[\Override]
    public function text(
        string $name,
        string $text,
        string $mediaType = 'text/plain; charset=utf-8',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        $this->stage($name, $text, $mediaType, AttachmentKind::Text, $retention);
    }

    #[\Override]
    public function bytes(
        string $name,
        string $bytes,
        string $mediaType = 'application/octet-stream',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        $this->stage($name, $bytes, $mediaType, AttachmentKind::Binary, $retention);
    }

    #[\Override]
    public function file(
        string $name,
        string $sourcePath,
        ?string $mediaType = null,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        $this->guard($name, $mediaType ?? 'application/octet-stream');
        $this->recordAttempt();
        $sequence = \count($this->attachments) + 1;
        $storageKey = $this->storageKey($name, $sequence);
        $attachment = $this->store->stageFile(
            $sourcePath,
            $name,
            $storageKey,
            $mediaType,
            $this->attempt,
            $retention,
            $this->configuration,
        );
        $this->record($attachment);
    }

    /**
     * Metadata visible to retry deciders before retention is applied.
     *
     * @return list<StagedAttachment>
     */
    public function collected(): array
    {
        return $this->attachments;
    }

    /**
     * Seals the attempt. Retention is decided when the terminal result is
     * published, after retries and scope teardown have finished.
     *
     * @return list<StagedAttachment>
     */
    public function seal(): array
    {
        $this->sealed = true;

        return $this->attachments;
    }

    private function stage(
        string $name,
        string $bytes,
        string $mediaType,
        AttachmentKind $kind,
        AttachmentRetention $retention,
    ): void {
        $this->guard($name, $mediaType);
        $this->recordAttempt();
        $sequence = \count($this->attachments) + 1;
        $attachment = $this->store->stageBytes(
            $bytes,
            $name,
            $this->storageKey($name, $sequence),
            $mediaType,
            $kind,
            $this->attempt,
            $retention,
            $this->configuration,
        );
        $this->record($attachment);
    }

    private function recordAttempt(): void
    {
        if ($this->attemptRecorded) {
            return;
        }

        $this->store->recordAttempt($this->testId, $this->attempt);
        $this->attemptRecorded = true;
    }

    private function record(StagedAttachment $attachment): void
    {
        if ($this->budget->bytes + $attachment->sizeBytes > $this->configuration->maxTestBytes) {
            $this->store->discard($attachment);

            throw AttachmentError::limit(\sprintf(
                'Attachments for this test exceed the %d byte limit',
                $this->configuration->maxTestBytes,
            ));
        }

        ++$this->budget->attachments;
        $this->budget->bytes += $attachment->sizeBytes;
        $this->attachments[] = $attachment;
    }

    private function guard(string $name, string $mediaType): void
    {
        if ($this->sealed) {
            throw AttachmentError::sealed();
        }

        if ($this->budget->attachments >= $this->configuration->maxAttachmentsPerTest) {
            throw AttachmentError::limit(\sprintf(
                'This test exceeds the %d attachment limit',
                $this->configuration->maxAttachmentsPerTest,
            ));
        }

        if ($name === ''
            || \strlen($name) > 120
            || \preg_match('//u', $name) !== 1
            || \preg_match('/[\/\\\\\x00-\x1F\x7F]/', $name) === 1
            || $name === '.'
            || $name === '..'
        ) {
            throw AttachmentError::invalidName($name);
        }

        if (\preg_match('~^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*(?:\s*;\s*[^=\s]+=(?:"[^"]*"|[^;\s]+))*$~', $mediaType) !== 1) {
            throw AttachmentError::invalidMediaType($mediaType);
        }
    }

    private function storageKey(string $name, int $sequence): string
    {
        $safeName = \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '.-');
        $safeName = \substr($safeName === '' ? 'attachment' : $safeName, 0, 80);
        $occurrence = ($this->names[$safeName] ?? 0) + 1;
        $this->names[$safeName] = $occurrence;

        if ($occurrence > 1) {
            $dot = \strrpos($safeName, '.');
            $safeName = $dot === false
                ? $safeName . '-' . $occurrence
                : \substr($safeName, 0, $dot) . '-' . $occurrence . \substr($safeName, $dot);
        }

        return \sprintf(
            '%s/attempt-%d/%02d-%s',
            ArtifactStore::testDirectory($this->testId),
            $this->attempt,
            $sequence,
            $safeName,
        );
    }
}
