<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;

/**
 * Owns private run staging, cross-worker quotas, publication, and cleanup.
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
    ) {}

    public static function open(
        ArtifactConfiguration $configuration,
        string $workingDirectory,
        string $runId,
    ): self {
        $configured = \rtrim($configuration->directory, '/');

        if ($configured === '' || \str_contains($configured, "\0")) {
            throw AttachmentError::storage('Attachment output directory is invalid');
        }

        if (!\str_starts_with($configured, '/')
            && \in_array('..', \explode('/', $configured), true)
        ) {
            throw AttachmentError::storage('Relative attachment output directory must stay inside the working directory');
        }

        if (\str_starts_with($configured, '/') && ($resolved = \realpath($configured)) !== false) {
            $configured = $resolved;
        }

        $public = $configured . '/' . $runId;
        $resolvedWorkingDirectory = \realpath($workingDirectory);
        $workingDirectory = $resolvedWorkingDirectory === false ? $workingDirectory : $resolvedWorkingDirectory;
        $output = \str_starts_with($configured, '/')
            ? $public
            : \rtrim($workingDirectory, '/') . '/' . $public;
        $staging = $output . '/.staging';
        $store = new self(new ArtifactSession($staging, $output), $configuration, $output, true);
        $stagingCreated = false;

        try {
            $store->createDirectorySafely($output);
            $warning = null;

            if (\file_exists($staging) || \is_link($staging)
                || !ErrorTrap::run(static fn(): bool => \mkdir($staging, 0o700), $warning)
            ) {
                throw AttachmentError::storage('Failed to create attachment staging directory' . ($warning === null ? '' : ': ' . $warning));
            }

            $stagingCreated = true;

            if (\file_put_contents($staging . '/.quota', "0 0\n", \LOCK_EX) === false) {
                throw AttachmentError::storage('Failed to initialize the attachment quota');
            }

            \chmod($staging . '/.quota', 0o600);
        } catch (\Throwable $error) {
            if ($stagingCreated) {
                $store->cleanup();
            }

            throw $error;
        }

        return $store;
    }

    public static function fromSession(
        ArtifactSession $session,
        ArtifactConfiguration $configuration,
    ): self {
        return new self($session, $configuration, null, false);
    }

    public function session(): ArtifactSession
    {
        return $this->session;
    }

    public function publicDirectory(): string
    {
        return $this->session->publicDirectory;
    }

    public function forAttempt(TestId $id, int $attempt, TestArtifactBudget $budget): StagedAttachments
    {
        return new StagedAttachments($this, $this->configuration, $id, $attempt, $budget);
    }

    public static function testDirectory(TestId $id): string
    {
        $raw = (string) $id;
        $slug = \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw), '.-');
        $slug = \substr($slug === '' ? 'test' : $slug, 0, 64);

        return $slug . '-' . \substr(\hash('sha256', $raw), 0, 12);
    }

    public function stageBytes(
        string $bytes,
        string $name,
        string $storageKey,
        string $mediaType,
        AttachmentKind $kind,
        int $attempt,
        AttachmentRetention $retention,
        ArtifactConfiguration $configuration,
    ): Attachment {
        $size = \strlen($bytes);
        $this->validateSize($size, $configuration);
        $this->reserve($size);

        try {
            $path = $this->preparePath($storageKey);
            $part = $path . '.part';
            $stream = \fopen($part, 'xb');

            if ($stream === false) {
                throw AttachmentError::storage('Failed to create attachment staging file');
            }

            try {
                $this->writeFully($stream, $bytes);
            } finally {
                \fclose($stream);
            }

            \chmod($part, 0o600);

            if (!\rename($part, $path)) {
                throw AttachmentError::storage('Failed to finalize attachment staging file');
            }

            $attachment = new Attachment(
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
            $this->release($size);

            throw $error;
        }
    }

    public function stageFile(
        string $sourcePath,
        string $name,
        string $storageKey,
        ?string $mediaType,
        int $attempt,
        AttachmentRetention $retention,
        ArtifactConfiguration $configuration,
    ): Attachment {
        if (\is_link($sourcePath)) {
            throw AttachmentError::source($sourcePath, 'must not be a symbolic link');
        }

        $source = \fopen($sourcePath, 'rb');

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

            try {
                $path = $this->preparePath($storageKey);
                $part = $path . '.part';
                $destination = \fopen($part, 'xb');

                if ($destination === false) {
                    throw AttachmentError::storage('Failed to create attachment staging file');
                }

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
                        $this->writeFully($destination, $chunk);
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
                    @\unlink($part);

                    throw AttachmentError::source($sourcePath, 'changed while it was being copied');
                }

                \chmod($part, 0o600);

                if (!\rename($part, $path)) {
                    throw AttachmentError::storage('Failed to finalize attachment staging file');
                }

                $attachment = new Attachment(
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
                $this->release($size);

                throw $error;
            }
        } finally {
            \fclose($source);
        }
    }

    public function discard(Attachment $attachment): void
    {
        $storageKey = $attachment->storageKey();

        if ($storageKey === null) {
            return;
        }

        @\unlink($this->session->stagingDirectory . '/' . $storageKey);
        @\unlink($this->metadataPath($storageKey));
        $this->release($attachment->sizeBytes);
    }

    public function publish(TestResult $result): TestResult
    {
        if ($result->attachments === []) {
            return $result;
        }

        if ($this->outputDirectory === null) {
            throw AttachmentError::storage('A worker attempted to publish attachments');
        }

        $published = [];

        foreach ($result->attachments as $attachment) {
            $storageKey = $attachment->storageKey();

            if ($storageKey === null || !$this->safeStorageKey($storageKey)) {
                throw AttachmentError::storage('Attachment metadata contains an unsafe storage key');
            }

            $source = $this->session->stagingDirectory . '/' . $storageKey;
            $destination = $this->outputDirectory . '/' . $storageKey;
            $parent = \dirname($destination);

            $this->createDirectorySafely($parent);

            if (\file_exists($destination) || \is_link($destination)) {
                throw AttachmentError::storage('Attachment output would overwrite an existing file');
            }

            if (\filesize($source) !== $attachment->sizeBytes || \hash_file('sha256', $source) !== $attachment->sha256) {
                throw AttachmentError::storage('Attachment staging content does not match its metadata');
            }

            if (!\rename($source, $destination) && (!\copy($source, $destination) || !\unlink($source))) {
                throw AttachmentError::storage('Failed to publish attachment');
            }

            \chmod($destination, 0o600);
            @\unlink($this->metadataPath($storageKey));
            $published[] = $attachment->published();
        }

        return $result->withAttachments($published);
    }

    /**
     * Recovers atomically completed evidence for a worker that died before
     * emitting TestFinished.
     */
    public function recover(TestResult $result): TestResult
    {
        $directory = $this->session->stagingDirectory . '/' . self::testDirectory($result->id);

        if (!\is_dir($directory)) {
            return $result;
        }

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

                    $attachments[] = Attachment::fromWire($map);
                }
            } catch (\Throwable) {
                // A partial or corrupt sidecar is not completed evidence.
            }
        }

        \usort(
            $attachments,
            static fn(Attachment $a, Attachment $b): int =>
                [$a->attempt, $a->storageKey()] <=> [$b->attempt, $b->storageKey()],
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
            !$entry->isLink() && $entry->isDir() ? @\rmdir($path) : @\unlink($path);
        }

        @\rmdir($directory);
    }

    private function validateSize(int $size, ArtifactConfiguration $configuration): void
    {
        if ($size < 0) {
            throw AttachmentError::storage('Attachment size could not be determined');
        }

        if ($size > $configuration->maxAttachmentBytes) {
            throw AttachmentError::limit(\sprintf(
                'Attachment size %d exceeds the %d byte limit',
                $size,
                $configuration->maxAttachmentBytes,
            ));
        }
    }

    /**
     * @param resource $stream
     */
    private function writeFully($stream, string $bytes): void
    {
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $written = \fwrite($stream, \substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw AttachmentError::storage('Failed to write the complete attachment');
            }

            $offset += $written;
        }
    }

    private function preparePath(string $storageKey): string
    {
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

    private function reserve(int $bytes): void
    {
        $this->updateQuota(function (int $count, int $used) use ($bytes): array {
            if ($count + 1 > $this->configuration->maxRunAttachments) {
                throw AttachmentError::limit(\sprintf(
                    'This run exceeds the %d attachment limit',
                    $this->configuration->maxRunAttachments,
                ));
            }

            if ($used + $bytes > $this->configuration->maxRunBytes) {
                throw AttachmentError::limit(\sprintf(
                    'Attachments for this run exceed the %d byte limit',
                    $this->configuration->maxRunBytes,
                ));
            }

            return [$count + 1, $used + $bytes];
        });
    }

    private function release(int $bytes): void
    {
        $this->updateQuota(static fn(int $count, int $used): array => [
            \max(0, $count - 1),
            \max(0, $used - $bytes),
        ]);
    }

    /**
     * @param \Closure(int, int): array{int, int} $update
     */
    private function updateQuota(\Closure $update): void
    {
        $path = $this->session->stagingDirectory . '/.quota';
        $stream = \fopen($path, 'c+');

        if ($stream === false || !\flock($stream, \LOCK_EX)) {
            throw AttachmentError::storage('Failed to lock the attachment quota');
        }

        try {
            \rewind($stream);
            $raw = \stream_get_contents($stream);
            $parts = \preg_split('/\s+/', \trim(\is_string($raw) ? $raw : '0 0'));
            [$count, $bytes] = $update((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));
            \ftruncate($stream, 0);
            \rewind($stream);
            \fwrite($stream, $count . ' ' . $bytes . "\n");
            \fflush($stream);
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

    private function writeMetadata(Attachment $attachment): void
    {
        $storageKey = $attachment->storageKey();

        if ($storageKey === null) {
            return;
        }

        $path = $this->metadataPath($storageKey);
        $part = $path . '.part';
        $encoded = \json_encode(
            $attachment->toWire(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (\file_put_contents($part, $encoded . "\n", \LOCK_EX) === false) {
            throw AttachmentError::storage('Failed to write attachment recovery metadata');
        }

        \chmod($part, 0o600);

        if (!\rename($part, $path)) {
            throw AttachmentError::storage('Failed to finalize attachment recovery metadata');
        }
    }

    private function metadataPath(string $storageKey): string
    {
        return $this->session->stagingDirectory . '/' . $storageKey . '.meta.json';
    }

    private function safeStorageKey(string $storageKey): bool
    {
        if ($storageKey === '' || \str_starts_with($storageKey, '/') || \str_contains($storageKey, '\\')) {
            return false;
        }

        return \array_all(
            \explode('/', $storageKey),
            static fn(string $segment): bool => !\in_array($segment, ['', '.', '..'], true),
        );
    }

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
