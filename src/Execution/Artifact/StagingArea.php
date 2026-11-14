<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Internal\Filesystem\RelativePath;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Text\DecimalInteger;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

/**
 * Owns the private staging directory of one run. Workers and the
 * orchestrator use the same staging area.
 *
 * The stageBytes() and stageFile() methods reserve run quota, write the
 * content atomically, and write a JSON sidecar beside it. A failure removes
 * the partial content and releases the quota. The recordAttempt() method
 * writes the attempt marker of a test. After a worker crash, the
 * recoveryCandidate() method reads the marker and the complete sidecars of a
 * test. The cleanup() method removes the directory.
 *
 * @internal
 */
final readonly class StagingArea
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    private RunQuota $quota;

    public function __construct(
        public ArtifactSession $session,
        ArtifactConfiguration $configuration,
    ) {
        $this->quota = new RunQuota($session->stagingDirectory, $configuration);
    }

    public static function testDirectory(TestId $id): string
    {
        $raw = (string) $id;
        $slug = \trim((string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw), '.-');
        $slug = \substr($slug === '' ? 'test' : $slug, 0, 64);

        return $slug . '-' . \substr(\hash('sha256', $raw), 0, 12);
    }

    public function hasTestDirectory(TestId $id): bool
    {
        return \is_dir($this->session->stagingDirectory . '/' . self::testDirectory($id));
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
        $size = \strlen($bytes);
        $this->validateSize($size, $configuration);

        return $this->writeAtomically(
            $storageKey,
            $size,
            static function ($destination) use ($bytes): string {
                StreamWriter::writeFully($destination, $bytes);

                return \hash('sha256', $bytes);
            },
            fn(string $path, string $digest): StagedAttachment => new StagedAttachment(
                $name,
                $kind,
                $mediaType,
                $size,
                $digest,
                $attempt,
                $this->session->publicDirectory . '/' . $storageKey,
                $retention,
                $storageKey,
            ),
        );
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
        $source = ErrorTrap::run(static function () use ($sourcePath) {
            if (\is_link($sourcePath)) {
                return null;
            }

            $stat = \stat($sourcePath);

            if (\is_array($stat) && ($stat['mode'] & 0170000) !== 0100000) {
                throw AttachmentError::source($sourcePath, 'is not a regular file');
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

            return $this->writeAtomically(
                $storageKey,
                $size,
                static function ($destination) use ($source, $sourcePath, $size, $before): string {
                    $hash = \hash_init('sha256');
                    $copied = 0;

                    while (!\feof($source)) {
                        $chunk = \fread($source, self::COPY_CHUNK_BYTES);

                        if ($chunk === false) {
                            throw AttachmentError::source($sourcePath, 'could not be read');
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        if (\strlen($chunk) > $size - $copied) {
                            throw AttachmentError::source($sourcePath, 'changed while it was being copied');
                        }

                        $copied += \strlen($chunk);
                        \hash_update($hash, $chunk);
                        StreamWriter::writeFully($destination, $chunk);
                    }

                    $after = \fstat($source);

                    if ($copied !== $size
                        || !\is_array($after)
                        || $after['size'] !== $before['size']
                        || $after['mtime'] !== $before['mtime']
                    ) {
                        throw AttachmentError::source($sourcePath, 'changed while it was being copied');
                    }

                    return \hash_final($hash);
                },
                fn(string $path, string $digest): StagedAttachment => new StagedAttachment(
                    $name,
                    AttachmentKind::File,
                    $mediaType ?? $this->detectMediaType($path),
                    $size,
                    $digest,
                    $attempt,
                    $this->session->publicDirectory . '/' . $storageKey,
                    $retention,
                    $storageKey,
                ),
            );
        } finally {
            ErrorTrap::run(static fn() => \fclose($source));
        }
    }

    /** @throws AttachmentError */
    public function discard(StagedAttachment $attachment): void
    {
        $storageKey = self::safeStorageKey($attachment->storageKey);
        $this->removeFile($this->metadataPath($storageKey), 'attachment recovery metadata');
        $this->removeFile($this->session->stagingDirectory . '/' . $storageKey, 'attachment staging file');
        $this->ensureStaging();
        $this->quota->release($attachment->sizeBytes);
    }

    /**
     * Removes the staged content and sidecar after publication. Removal is
     * best effort, because cleanup removes the staging directory later.
     */
    public function forgetPublished(string $storageKey): void
    {
        $source = $this->stagedPath($storageKey);
        ErrorTrap::run(static fn() => \unlink($source));
        $metadata = $this->metadataPath($storageKey);
        ErrorTrap::run(static fn() => \unlink($metadata));
    }

    public function stagedPath(string $storageKey): string
    {
        return $this->session->stagingDirectory . '/' . $storageKey;
    }

    /**
     * @return non-empty-string
     * @throws AttachmentError
     */
    public static function safeStorageKey(string $storageKey): string
    {
        if (!RelativePath::isSafe($storageKey)) {
            throw AttachmentError::storage('Attachment metadata contains an unsafe storage key');
        }

        return $storageKey;
    }

    /** @throws AttachmentError */
    public function recordAttempt(TestId $id, int $attempt): void
    {
        $this->ensureStaging();
        $directory = $this->session->stagingDirectory . '/' . self::testDirectory($id);

        if (!\is_dir($directory)
            && !ErrorTrap::run(static fn() => \mkdir($directory, 0o700, true), $warning)
            && !\is_dir($directory)
        ) {
            throw AttachmentError::storage('Failed to create attachment staging subdirectory' . ($warning === null ? '' : ': ' . $warning));
        }

        $path = $directory . '/.attempt';
        $part = $path . '.part-' . \bin2hex(\random_bytes(4));

        if (ErrorTrap::run(static fn() => \file_put_contents($part, $attempt . "\n", \LOCK_EX), $warning) === false) {
            ErrorTrap::run(static fn() => \unlink($part));

            throw AttachmentError::storage('Failed to record the current test attempt' . ($warning === null ? '' : ': ' . $warning));
        }

        ErrorTrap::run(static fn() => \chmod($part, 0o600));

        if (!ErrorTrap::run(static fn() => \rename($part, $path), $warning)) {
            ErrorTrap::run(static fn() => \unlink($part));

            throw AttachmentError::storage('Greenlight did not finalize the current test attempt record' . ($warning === null ? '' : ': ' . $warning));
        }
    }

    /**
     * Returns the result with the recorded attempt floor and each complete
     * staged attachment of the test, in attempt and creation order.
     */
    public function recoveryCandidate(TestResult $result): TestResult
    {
        $directory = $this->session->stagingDirectory . '/' . self::testDirectory($result->id);

        if (!\is_dir($directory)) {
            return $result;
        }

        $attemptRecord = ErrorTrap::run(
            static fn() => \file_get_contents($directory . '/.attempt'),
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
            static function (StagedAttachment $a, StagedAttachment $b): int {
                $attemptOrder = $a->attempt <=> $b->attempt;

                return $attemptOrder !== 0 ? $attemptOrder : \strnatcmp($a->storageKey, $b->storageKey);
            },
        );

        return $attachments === [] ? $result : $result->withAttachments($attachments);
    }

    public function cleanup(): void
    {
        $directory = $this->session->stagingDirectory;

        if (\is_link($directory)) {
            ErrorTrap::run(static fn() => \unlink($directory));

            return;
        }

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
                ErrorTrap::run(static fn() => \rmdir($path));
            } else {
                ErrorTrap::run(static fn() => \unlink($path));
            }
        }

        ErrorTrap::run(static fn() => \rmdir($directory));
    }

    /**
     * Reserves quota, writes the content to a `.part` file, and renames the
     * file into place. It then writes the sidecar. A failure removes the
     * partial files and releases the quota.
     *
     * @param \Closure(resource): string $write Writes the content to the stream and returns its SHA-256 digest.
     * @param \Closure(string, string): StagedAttachment $attachment Builds the metadata from the staged path and digest.
     * @throws AttachmentError
     */
    private function writeAtomically(string $storageKey, int $size, \Closure $write, \Closure $attachment): StagedAttachment
    {
        $this->ensureStaging();
        $this->quota->reserve($size);
        $path = null;
        $part = null;
        $stagingFileCreated = false;

        try {
            $path = $this->preparePath($storageKey);
            $part = $path . '.part';
            $stream = ErrorTrap::run(static fn() => \fopen($part, 'xb'), $warning);

            if ($stream === false) {
                throw AttachmentError::storage('Greenlight did not create the attachment staging file' . ($warning === null ? '' : ': ' . $warning));
            }

            $stagingFileCreated = true;

            try {
                $digest = $write($stream);
            } finally {
                ErrorTrap::run(static fn() => \fclose($stream));
            }

            ErrorTrap::run(static fn() => \chmod($part, 0o600));

            if (!ErrorTrap::run(static fn() => \rename($part, $path), $warning)) {
                throw AttachmentError::storage('Failed to finalize attachment staging file' . ($warning === null ? '' : ': ' . $warning));
            }

            $staged = $attachment($path, $digest);
            $this->writeMetadata($staged);

            return $staged;
        } catch (\Throwable $error) {
            if ($stagingFileCreated) {
                $this->rollbackStagedAttachment($storageKey, $path, $part);
            }

            $this->ensureStaging();
            $this->quota->release($size);

            throw $error;
        }
    }

    /** @throws AttachmentError */
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

    /** @throws AttachmentError */
    private function preparePath(string $storageKey): string
    {
        $path = $this->session->stagingDirectory . '/' . $storageKey;
        $parent = \dirname($path);

        if (!\is_dir($parent) && !ErrorTrap::run(static fn() => \mkdir($parent, 0o700, true), $warning)) {
            throw AttachmentError::storage('Failed to create attachment staging subdirectory' . ($warning === null ? '' : ': ' . $warning));
        }

        if (\file_exists($path) || \is_link($path)) {
            throw AttachmentError::storage('Attachment staging path already exists');
        }

        return $path;
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

        $type = \finfo_file($finfo, $path);

        return \is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    /** @throws AttachmentError */
    private function writeMetadata(StagedAttachment $attachment): void
    {
        $path = $this->metadataPath($attachment->storageKey);
        $part = $path . '.part';
        $encoded = \json_encode(
            $attachment->toWire(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (ErrorTrap::run(static fn() => \file_put_contents($part, $encoded . "\n", \LOCK_EX), $warning) === false) {
            ErrorTrap::run(static fn() => \unlink($part));

            throw AttachmentError::storage('Greenlight did not write attachment recovery metadata' . ($warning === null ? '' : ': ' . $warning));
        }

        ErrorTrap::run(static fn() => \chmod($part, 0o600));

        if (!ErrorTrap::run(static fn() => \rename($part, $path), $warning)) {
            ErrorTrap::run(static fn() => \unlink($part));

            throw AttachmentError::storage('Failed to finalize attachment recovery metadata' . ($warning === null ? '' : ': ' . $warning));
        }
    }

    /** @throws AttachmentError */
    private function ensureStaging(): void
    {
        $directory = $this->session->stagingDirectory;

        if (\is_link($directory)) {
            throw AttachmentError::storage('Attachment staging directory is unsafe');
        }

        if (!\is_dir($directory)
            && !ErrorTrap::run(static fn() => \mkdir($directory, 0o700), $warning)
            && !\is_dir($directory)
        ) {
            throw AttachmentError::storage('Failed to create attachment staging directory' . ($warning === null ? '' : ': ' . $warning));
        }

        ErrorTrap::run(static fn() => \chmod($directory, 0o700));
    }

    /** @throws AttachmentError */
    private function rollbackStagedAttachment(string $storageKey, ?string $path, ?string $part): void
    {
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

    /** @throws AttachmentError */
    private function removeFile(string $path, string $description): void
    {
        if (!\file_exists($path) && !\is_link($path)) {
            return;
        }

        if (!ErrorTrap::run(static fn() => \unlink($path), $warning)) {
            throw AttachmentError::storage('Greenlight did not remove ' . $description . ($warning === null ? '' : ': ' . $warning));
        }
    }

    private function metadataPath(string $storageKey): string
    {
        return $this->session->stagingDirectory . '/' . $storageKey . '.meta.json';
    }
}
