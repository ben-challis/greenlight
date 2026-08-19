<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\DecimalInteger;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;

/**
 * Controls private run staging, quotas for all workers, publication, and cleanup.
 *
 * @internal
 */
final class ArtifactStore
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    private bool $cleaned = false;

    private function __construct(
        private readonly ArtifactSession $session,
        private readonly ArtifactConfiguration $configuration,
        private readonly ?string $outputDirectory,
        private readonly bool $ownsStaging,
        private readonly FileCopier $fileCopier,
    ) {}

    /**
     * @throws AttachmentError
     */
    public static function open(
        ArtifactConfiguration $configuration,
        string $workingDirectory,
        string $runId,
        ?FileCopier $fileCopier = null,
    ): self {
        $configured = \rtrim($configuration->directory, '/');

        if ($configured === '' || \str_contains($configured, "\0")) {
            throw AttachmentError::storage('Attachment output directory is invalid');
        }

        if (!\str_starts_with($configured, '/')
            && \in_array('..', \explode('/', $configured), true)
        ) {
            throw AttachmentError::storage('Keep a relative attachment output directory inside the working directory');
        }

        if (\str_starts_with($configured, '/')
            && ($resolved = ErrorTrap::run(static fn(): string|false => \realpath($configured))) !== false
        ) {
            $configured = $resolved;
        }

        $public = $configured . '/' . $runId;
        $resolvedWorkingDirectory = ErrorTrap::run(static fn(): string|false => \realpath($workingDirectory));
        $workingDirectory = $resolvedWorkingDirectory === false ? $workingDirectory : $resolvedWorkingDirectory;
        $output = \str_starts_with($configured, '/')
            ? $public
            : \rtrim($workingDirectory, '/') . '/' . $public;
        $staging = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-artifacts-'
            . \substr(\hash('sha256', $runId), 0, 16)
            . '-' . \bin2hex(\random_bytes(6));
        return new self(
            new ArtifactSession($staging, $output),
            $configuration,
            $output,
            true,
            $fileCopier ?? new NativeFileCopier(),
        );
    }

    public static function fromSession(
        ArtifactSession $session,
        ArtifactConfiguration $configuration,
        ?FileCopier $fileCopier = null,
    ): self {
        return new self(
            $session,
            $configuration,
            null,
            false,
            $fileCopier ?? new NativeFileCopier(),
        );
    }

    public function session(): ArtifactSession
    {
        return $this->session;
    }

    public function publicDirectory(): string
    {
        return $this->session->publicDirectory;
    }

    /**
     * @throws AttachmentError
     */
    public function forAttempt(TestId $id, int $attempt, TestArtifactBudget $budget): StagedAttachments
    {
        $attemptRecorded = \is_dir(
            $this->session->stagingDirectory . '/' . self::testDirectory($id),
        );

        if ($attemptRecorded) {
            $this->recordAttempt($id, $attempt);
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
        $raw = (string) $id;
        $slug = \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw), '.-');
        $slug = \substr($slug === '' ? 'test' : $slug, 0, 64);

        return $slug . '-' . \substr(\hash('sha256', $raw), 0, 12);
    }

    /**
     * @throws AttachmentError
     */
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
        $size = \strlen($bytes);
        $this->validateSize($size, $configuration);
        $this->reserve($size);
        $path = null;
        $part = null;
        $stagingFileCreated = false;

        try {
            $path = $this->preparePath($storageKey);
            $part = $path . '.part';
            $stream = \fopen($part, 'xb');

            if ($stream === false) {
                throw AttachmentError::storage('Greenlight did not create the attachment staging file');
            }

            $stagingFileCreated = true;

            try {
                StreamWriter::writeFully($stream, $bytes);
            } finally {
                \fclose($stream);
            }

            \chmod($part, 0o600);

            if (!\rename($part, $path)) {
                throw AttachmentError::storage('Failed to finalize attachment staging file');
            }

            $attachment = new StagedAttachment(
                $name,
                $kind,
                $mediaType,
                $size,
                \hash('sha256', $bytes),
                $attempt,
                $this->session->publicDirectory . '/' . $storageKey,
                $retention,
                $storageKey,
            );
            $this->writeMetadata($attachment);

            return $attachment;
        } catch (\Throwable $error) {
            if ($stagingFileCreated) {
                $this->rollbackStagedAttachment($storageKey, $path, $part);
            }

            $this->release($size);

            throw $error;
        }
    }

    /**
     * @throws AttachmentError
     */
    public function stageFile(
        string $sourcePath,
        string $name,
        string $storageKey,
        ?string $mediaType,
        int $attempt,
        AttachmentRetention $retention,
        ArtifactConfiguration $configuration,
    ): StagedAttachment {
        $source = ErrorTrap::run(static function () use ($sourcePath) {
            if (\is_link($sourcePath)) {
                return null;
            }

            return \fopen($sourcePath, 'rb');
        });

        if ($source === null) {
            throw AttachmentError::source($sourcePath, 'is a symbolic link. Use a source path that is not a symbolic link');
        }

        if ($source === false) {
            throw AttachmentError::source($sourcePath, 'is not a readable regular file');
        }

        try {
            $before = \fstat($source);

            if (!\is_array($before) || ($before['mode'] & 0170000) !== 0100000) {
                throw AttachmentError::source($sourcePath, 'is not a regular file');
            }

            $size = $before['size'];
            $this->validateSize($size, $configuration);
            $this->reserve($size);
            $path = null;
            $part = null;
            $stagingFileCreated = false;

            try {
                $path = $this->preparePath($storageKey);
                $part = $path . '.part';
                $destination = \fopen($part, 'xb');

                if ($destination === false) {
                    throw AttachmentError::storage('Greenlight did not create the attachment staging file');
                }

                $stagingFileCreated = true;
                $hash = \hash_init('sha256');
                $copied = 0;

                try {
                    while (!\feof($source)) {
                        $chunk = \fread($source, self::COPY_CHUNK_BYTES);

                        if ($chunk === false) {
                            throw AttachmentError::source($sourcePath, 'could not be read');
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $copied += \strlen($chunk);
                        \hash_update($hash, $chunk);
                        StreamWriter::writeFully($destination, $chunk);
                    }
                } finally {
                    \fclose($destination);
                }

                $after = \fstat($source);

                if ($copied !== $size
                    || !\is_array($after)
                    || $after['size'] !== $before['size']
                    || $after['mtime'] !== $before['mtime']
                ) {
                    ErrorTrap::run(static fn(): bool => \unlink($part));

                    throw AttachmentError::source($sourcePath, 'changed while it was being copied');
                }

                \chmod($part, 0o600);

                if (!\rename($part, $path)) {
                    throw AttachmentError::storage('Failed to finalize attachment staging file');
                }

                $attachment = new StagedAttachment(
                    $name,
                    AttachmentKind::File,
                    $mediaType ?? $this->detectMediaType($path),
                    $copied,
                    \hash_final($hash),
                    $attempt,
                    $this->session->publicDirectory . '/' . $storageKey,
                    $retention,
                    $storageKey,
                );
                $this->writeMetadata($attachment);

                return $attachment;
            } catch (\Throwable $error) {
                if ($stagingFileCreated) {
                    $this->rollbackStagedAttachment($storageKey, $path, $part);
                }

                $this->release($size);

                throw $error;
            }
        } finally {
            \fclose($source);
        }
    }

    /**
     * @throws AttachmentError
     */
    public function discard(StagedAttachment $attachment): void
    {
        $storageKey = $attachment->storageKey;
        $this->assertSafeStorageKey($storageKey);
        $this->removeFile($this->metadataPath($storageKey), 'attachment recovery metadata');
        $this->removeFile($this->session->stagingDirectory . '/' . $storageKey, 'attachment staging file');
        $this->release($attachment->sizeBytes);
    }

    /**
     * @throws AttachmentError
     */
    public function publish(TestResult $result): TestResult
    {
        if ($result->attachments === []) {
            return $result;
        }

        if ($this->outputDirectory === null) {
            throw AttachmentError::storage('A worker attempted to publish attachments');
        }

        $published = [];
        $problematic = !$result->outcome->isSuccessful() || \array_any(
            $result->transformations,
            static fn($transformation): bool => !$transformation->from->isSuccessful(),
        );

        foreach ($result->attachments as $attachment) {
            if (!$attachment instanceof StagedAttachment) {
                throw AttachmentError::storage('Attachment metadata does not contain a staging coordinate');
            }

            if (!$problematic
                && $attachment->attempt >= $result->attempts
                && $attachment->retention === AttachmentRetention::OnFailure
            ) {
                $this->discard($attachment);

                continue;
            }

            $storageKey = $attachment->storageKey;
            $this->assertSafeStorageKey($storageKey);

            $source = $this->session->stagingDirectory . '/' . $storageKey;
            $destination = $this->outputDirectory . '/' . $storageKey;
            $parent = \dirname($destination);
            $part = $destination . '.part-' . \bin2hex(\random_bytes(4));

            $this->createDirectorySafely($parent);

            if (\file_exists($destination) || \is_link($destination)
                || \file_exists($part) || \is_link($part)
            ) {
                throw AttachmentError::storage('An attachment output path already exists');
            }

            if (\filesize($source) !== $attachment->sizeBytes || \hash_file('sha256', $source) !== $attachment->sha256) {
                throw AttachmentError::storage('Attachment staging content does not match its metadata');
            }

            try {
                $this->fileCopier->copy($source, $part);

                if (\filesize($part) !== $attachment->sizeBytes || \hash_file('sha256', $part) !== $attachment->sha256) {
                    throw AttachmentError::storage('Published attachment content does not match its metadata');
                }

                \chmod($part, 0o600);

                if (!\rename($part, $destination)) {
                    throw AttachmentError::storage('Failed to publish attachment');
                }
            } catch (\Throwable $error) {
                ErrorTrap::run(static fn(): bool => \unlink($part));

                throw $error;
            }

            ErrorTrap::run(static fn(): bool => \unlink($source));
            ErrorTrap::run(fn(): bool => \unlink($this->metadataPath($storageKey)));
            $published[] = $attachment->published();
        }

        return $result->withAttachments($published);
    }

    /**
     * Recovers evidence that completed atomically from a worker that stopped
     * before TestFinished.
     * @throws AttachmentError
     */
    public function recover(TestResult $result): TestResult
    {
        $directory = $this->session->stagingDirectory . '/' . self::testDirectory($result->id);

        if (!\is_dir($directory)) {
            return $result;
        }

        $attemptRecord = ErrorTrap::run(
            static fn(): string|false => \file_get_contents($directory . '/.attempt'),
        );
        $attempt = DecimalInteger::parse(\trim((string) $attemptRecord));

        if ($attempt !== null && $attempt > 0) {
            $result = $result->withAttempts(\max($result->attempts, $attempt));
        }
        /** @var list<StagedAttachment> $attachments */
        $attachments = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if (!$entry->isFile() || !\str_ends_with($entry->getFilename(), '.meta.json')) {
                continue;
            }

            try {
                $decoded = \json_decode(
                    (string) \file_get_contents($entry->getPathname()),
                    true,
                    flags: \JSON_THROW_ON_ERROR,
                );

                if (\is_array($decoded)) {
                    $map = [];

                    foreach ($decoded as $key => $value) {
                        $map[(string) $key] = $value;
                    }

                    $attachments[] = StagedAttachment::fromWire($map);
                }
            } catch (\Throwable) {
                // A partial or corrupt sidecar is not complete evidence.
            }
        }

        \usort(
            $attachments,
            static fn(StagedAttachment $a, StagedAttachment $b): int =>
                [$a->attempt, $a->storageKey] <=> [$b->attempt, $b->storageKey],
        );

        return $attachments === [] ? $result : $this->publish($result->withAttachments($attachments));
    }

    public function cleanup(): void
    {
        if ($this->cleaned || !$this->ownsStaging) {
            return;
        }

        $this->cleaned = true;
        $directory = $this->session->stagingDirectory;

        if (!\is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $path = $entry->getPathname();

            if (!$entry->isLink() && $entry->isDir()) {
                ErrorTrap::run(static fn(): bool => \rmdir($path));
            } else {
                ErrorTrap::run(static fn(): bool => \unlink($path));
            }
        }

        ErrorTrap::run(static fn(): bool => \rmdir($directory));
    }

    /**
     * @throws AttachmentError
     */
    private function validateSize(int $size, ArtifactConfiguration $configuration): void
    {
        if ($size < 0) {
            throw AttachmentError::storage('Attachment size could not be determined');
        }

        if ($size > $configuration->maxAttachmentBytes) {
            throw AttachmentError::limit(\sprintf(
                'Attachment size %d exceeds the limit of %d bytes',
                $size,
                $configuration->maxAttachmentBytes,
            ));
        }
    }

    /**
     * @throws AttachmentError
     */
    private function preparePath(string $storageKey): string
    {
        $this->ensureStaging();
        $path = $this->session->stagingDirectory . '/' . $storageKey;
        $parent = \dirname($path);

        if (!\is_dir($parent) && !ErrorTrap::run(static fn(): bool => \mkdir($parent, 0o700, true), $warning)) {
            throw AttachmentError::storage('Failed to create attachment staging subdirectory' . ($warning === null ? '' : ': ' . $warning));
        }

        if (\file_exists($path) || \is_link($path)) {
            throw AttachmentError::storage('Attachment staging path already exists');
        }

        return $path;
    }

    /**
     * @throws AttachmentError
     */
    private function reserve(int $bytes): void
    {
        $this->updateQuota(function (int $count, int $used) use ($bytes): array {
            if ($count >= $this->configuration->maxRunAttachments) {
                throw AttachmentError::limit(\sprintf(
                    'This run has reached the limit of %d attachments',
                    $this->configuration->maxRunAttachments,
                ));
            }

            if ($bytes > $this->configuration->maxRunBytes
                || $used > $this->configuration->maxRunBytes - $bytes
            ) {
                throw AttachmentError::limit(\sprintf(
                    'Attachments for this run exceed the limit of %d bytes',
                    $this->configuration->maxRunBytes,
                ));
            }

            return [$count + 1, $used + $bytes];
        });
    }

    /**
     * @throws AttachmentError
     */
    private function release(int $bytes): void
    {
        $this->updateQuota(static fn(int $count, int $used): array => [
            \max(0, $count - 1),
            \max(0, $used - $bytes),
        ]);
    }

    /**
     * @param \Closure(int, int): array{int, int} $update
     * @throws AttachmentError
     */
    private function updateQuota(\Closure $update): void
    {
        $this->ensureStaging();
        $path = $this->session->stagingDirectory . '/.quota';

        if (\is_link($path) || (\file_exists($path) && !\is_file($path))) {
            throw AttachmentError::storage('Attachment quota path is unsafe');
        }

        $stream = \fopen($path, 'c+');

        if ($stream === false) {
            throw AttachmentError::storage('Failed to lock the attachment quota');
        }

        if (!\flock($stream, \LOCK_EX)) {
            \fclose($stream);

            throw AttachmentError::storage('Failed to lock the attachment quota');
        }

        try {
            \rewind($stream);
            $raw = \stream_get_contents($stream);
            $raw = \is_string($raw) ? \trim($raw) : '';

            if ($raw !== '' && \preg_match('/^\d+\s+\d+$/', $raw) !== 1) {
                throw AttachmentError::storage('Attachment quota metadata is corrupt');
            }

            $parts = \preg_split('/\s+/', $raw === '' ? '0 0' : $raw);
            $count = DecimalInteger::parse($parts[0] ?? '');
            $bytes = DecimalInteger::parse($parts[1] ?? '');

            if ($count === null || $bytes === null) {
                throw AttachmentError::storage('Attachment quota metadata is corrupt');
            }

            [$count, $bytes] = $update($count, $bytes);
            \rewind($stream);
            $encoded = \sprintf('%020d %020d' . "\n", $count, $bytes);

            if (\fwrite($stream, $encoded) !== \strlen($encoded)
                || !\ftruncate($stream, \strlen($encoded))
                || !\fflush($stream)
            ) {
                throw AttachmentError::storage('Failed to update the attachment quota');
            }

            \chmod($path, 0o600);
        } finally {
            \flock($stream, \LOCK_UN);
            \fclose($stream);
        }
    }

    private function detectMediaType(string $path): string
    {
        if (!\function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = \finfo_open(\FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        try {
            $type = \finfo_file($finfo, $path);

            return \is_string($type) && $type !== '' ? $type : 'application/octet-stream';
        } finally {
            \finfo_close($finfo);
        }
    }

    /**
     * @throws AttachmentError
     */
    private function writeMetadata(StagedAttachment $attachment): void
    {
        $storageKey = $attachment->storageKey;
        $path = $this->metadataPath($storageKey);
        $part = $path . '.part';
        $encoded = \json_encode(
            $attachment->toWire(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (\file_put_contents($part, $encoded . "\n", \LOCK_EX) === false) {
            ErrorTrap::run(static fn(): bool => \unlink($part));

            throw AttachmentError::storage('Greenlight did not write attachment recovery metadata');
        }

        \chmod($part, 0o600);

        if (!\rename($part, $path)) {
            ErrorTrap::run(static fn(): bool => \unlink($part));

            throw AttachmentError::storage('Failed to finalize attachment recovery metadata');
        }
    }

    /**
     * @throws AttachmentError
     */
    public function recordAttempt(TestId $id, int $attempt): void
    {
        $this->ensureStaging();
        $directory = $this->session->stagingDirectory . '/' . self::testDirectory($id);

        if (!\is_dir($directory)
            && !ErrorTrap::run(static fn(): bool => \mkdir($directory, 0o700, true), $warning)
            && !\is_dir($directory)
        ) {
            throw AttachmentError::storage('Failed to create attachment staging subdirectory' . ($warning === null ? '' : ': ' . $warning));
        }

        $path = $directory . '/.attempt';
        $part = $path . '.part-' . \bin2hex(\random_bytes(4));

        if (\file_put_contents($part, $attempt . "\n", \LOCK_EX) === false) {
            ErrorTrap::run(static fn(): bool => \unlink($part));

            throw AttachmentError::storage('Failed to record the current test attempt');
        }

        \chmod($part, 0o600);

        if (!\rename($part, $path)) {
            ErrorTrap::run(static fn(): bool => \unlink($part));

            throw AttachmentError::storage('Greenlight did not finalize the current test attempt record');
        }
    }

    /**
     * @throws AttachmentError
     */
    private function ensureStaging(): void
    {
        $directory = $this->session->stagingDirectory;

        if (\is_link($directory)) {
            throw AttachmentError::storage('Attachment staging directory is unsafe');
        }

        if (!\is_dir($directory)
            && !ErrorTrap::run(static fn(): bool => \mkdir($directory, 0o700), $warning)
            && !\is_dir($directory)
        ) {
            throw AttachmentError::storage('Failed to create attachment staging directory' . ($warning === null ? '' : ': ' . $warning));
        }

        \chmod($directory, 0o700);
    }

    /**
     * @throws AttachmentError
     */
    private function rollbackStagedAttachment(
        string $storageKey,
        ?string $path,
        ?string $part,
    ): void {
        // writeMetadata() removes its temporary file when it fails. A final
        // metadata path that remains belongs to an earlier operation.
        foreach ([
            $part,
            $path,
            $this->metadataPath($storageKey) . '.part',
        ] as $candidate) {
            if ($candidate !== null) {
                $this->removeFile($candidate, 'incomplete attachment staging data');
            }
        }
    }

    /**
     * @throws AttachmentError
     */
    private function removeFile(string $path, string $description): void
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return;
        }

        if (!\unlink($path)) {
            throw AttachmentError::storage('Greenlight did not remove ' . $description);
        }
    }

    private function metadataPath(string $storageKey): string
    {
        return $this->session->stagingDirectory . '/' . $storageKey . '.meta.json';
    }

    /**
     * @throws AttachmentError
     */
    private function assertSafeStorageKey(string $storageKey): void
    {
        if ($storageKey === '' || \str_starts_with($storageKey, '/') || \str_contains($storageKey, '\\')) {
            throw AttachmentError::storage('Attachment metadata contains an unsafe storage key');
        }

        if (!\array_all(
            \explode('/', $storageKey),
            static fn(string $segment): bool => !\in_array($segment, ['', '.', '..'], true),
        )) {
            throw AttachmentError::storage('Attachment metadata contains an unsafe storage key');
        }
    }

    /**
     * @throws AttachmentError
     */
    private function createDirectorySafely(string $directory): void
    {
        $absolute = \str_starts_with($directory, '/');
        $current = $absolute ? '/' : '';

        foreach (\explode('/', \trim($directory, '/')) as $segment) {
            $current = $current === '/' ? '/' . $segment : ($current === '' ? $segment : $current . '/' . $segment);

            if (\is_link($current)) {
                throw AttachmentError::storage('Attachment output directory contains a symbolic link');
            }

            if (\is_dir($current)) {
                continue;
            }

            if (\file_exists($current)) {
                throw AttachmentError::storage('Attachment output path contains a non-directory entry');
            }

            if (!ErrorTrap::run(static fn(): bool => \mkdir($current, 0o700), $warning)) {
                throw AttachmentError::storage('Failed to create attachment output directory' . ($warning === null ? '' : ': ' . $warning));
            }
        }
    }
}
