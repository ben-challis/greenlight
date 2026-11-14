<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Internal\Filesystem\RelativePath;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Reads, writes, and verifies the ownership metadata of one run directory.
 *
 * read() decodes the metadata file. write() replaces it atomically.
 * contentSizes() lists content files from `stat` data. contentManifest() adds
 * the SHA-256 digest of each file. completedFiles() validates the manifest in
 * completed metadata, and verifiedRecord() compares that manifest with the
 * content digests.
 *
 * @internal
 */
final class RunManifest
{
    public const int VERSION = 1;
    public const string OWNER = 'greenlight';
    public const string METADATA_FILE = '.greenlight-run.json';
    public const string LOCK_FILE = '.greenlight-run.lock';

    private const int MAX_METADATA_BYTES = 16 * 1024 * 1024;

    /** @codeCoverageIgnore */
    private function __construct() {}

    /** @return non-empty-string|null */
    public static function validatedRunId(string $runId): ?string
    {
        if ($runId === '' || \in_array($runId, ['.', '..'], true)
            || \preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $runId) !== 1
        ) {
            return null;
        }

        return $runId;
    }

    /**
     * Returns the metadata map, or an empty map when the file is missing,
     * empty, too large, or not a JSON object.
     *
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public static function read(string $directory): array
    {
        $path = $directory . '/' . self::METADATA_FILE;
        if (ErrorTrap::run(static fn() => \is_link($path)) || !ErrorTrap::run(static fn() => \is_file($path))) {
            return [];
        }
        $size = ErrorTrap::run(static fn() => \filesize($path));
        if (!\is_int($size) || $size < 1 || $size > self::MAX_METADATA_BYTES) {
            return [];
        }
        $decoded = \json_decode((string) ErrorTrap::run(static fn() => \file_get_contents($path)), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded) || \array_is_list($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws \RuntimeException
     */
    public static function write(string $directory, array $metadata): void
    {
        $path = $directory . '/' . self::METADATA_FILE;
        $part = $path . '.part-' . \bin2hex(\random_bytes(6));
        $encoded = \json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        if (ErrorTrap::run(static fn() => \file_put_contents($part, $encoded . "\n", \LOCK_EX)) === false) {
            ErrorTrap::run(static fn() => \unlink($part));
            throw new \RuntimeException('Greenlight did not write artifact run metadata.');
        }
        ErrorTrap::run(static fn() => \chmod($part, 0o600));
        if (!ErrorTrap::run(static fn() => \rename($part, $path))) {
            ErrorTrap::run(static fn() => \unlink($part));
            throw new \RuntimeException('Greenlight did not finalize artifact run metadata.');
        }
    }

    /**
     * Returns the start time when the metadata has the current version, the
     * Greenlight owner, the run ID, and a valid start time. Otherwise, it
     * returns null.
     *
     * @param array<string, mixed> $metadata
     *
     * @return int<0, max>|null
     */
    public static function ownedStartedAt(array $metadata, string $runId): ?int
    {
        $startedAt = $metadata['startedAt'] ?? null;
        if (($metadata['version'] ?? null) !== self::VERSION
            || ($metadata['owner'] ?? null) !== self::OWNER
            || ($metadata['runId'] ?? null) !== $runId
            || !\is_int($startedAt)
            || $startedAt < 0
        ) {
            return null;
        }

        return $startedAt;
    }

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public static function startedAt(string $directory, string $runId): int
    {
        $decoded = self::read($directory);
        $startedAt = self::ownedStartedAt($decoded, $runId);
        if ($startedAt === null || ($decoded['state'] ?? null) !== 'active') {
            throw new \RuntimeException('Artifact run metadata is not an active Greenlight record.');
        }

        return $startedAt;
    }

    /**
     * Returns the validated manifest of completed metadata, or null when the
     * metadata does not describe a completed run.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>|null
     */
    public static function completedFiles(array $metadata): ?array
    {
        if (($metadata['state'] ?? null) !== 'completed'
            || !\is_int($metadata['completedAt'] ?? null) || $metadata['completedAt'] < 0
            || !\is_array($metadata['files'] ?? null)
        ) {
            return null;
        }

        return self::validatedFiles($metadata['files']);
    }

    /**
     * Returns the validated manifest entries, or null when an entry has an
     * unsafe path, a negative size, or an invalid digest.
     *
     * @param array<mixed> $raw
     *
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>|null
     */
    public static function validatedFiles(array $raw): ?array
    {
        $files = [];
        foreach ($raw as $path => $file) {
            if (!\is_string($path) || !RelativePath::isSafe($path) || !\is_array($file)
                || !\is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !\is_string($file['sha256'] ?? null)
                || \preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1
            ) {
                return null;
            }
            $files[$path] = ['bytes' => $file['bytes'], 'sha256' => $file['sha256']];
        }
        \ksort($files);

        return $files;
    }

    /**
     * Reads a completed run record and verifies the SHA-256 digest of each
     * content file against the completion manifest.
     *
     * @throws \Throwable
     */
    public static function verifiedRecord(string $directory, string $runId): ?ArtifactRunRecord
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
        ) {
            return null;
        }
        $runId = self::validatedRunId($runId);
        if ($runId === null) {
            return null;
        }
        $decoded = self::read($directory);
        $startedAt = self::ownedStartedAt($decoded, $runId);
        $files = $startedAt === null ? null : self::completedFiles($decoded);
        if ($startedAt === null || $files === null || !\is_int($decoded['completedAt'] ?? null)
            || self::contentManifest($directory) !== $files
        ) {
            return null;
        }

        return new ArtifactRunRecord(
            $runId,
            $startedAt,
            $decoded['completedAt'],
            $files,
            self::withInternalBytes($directory, self::manifestBytes($files)),
        );
    }

    /**
     * Returns the SHA-256 manifest of the content below a run directory.
     *
     * The method reuses each digest in $known when the file size matches.
     * It reads all other files.
     *
     * @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $known
     *
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>
     * @throws \RuntimeException
     */
    public static function contentManifest(string $directory, array $known = []): array
    {
        $files = [];
        foreach (self::contentSizes($directory) as $relative => $size) {
            $recorded = $known[$relative] ?? null;
            if ($recorded !== null && $recorded['bytes'] === $size) {
                $files[$relative] = $recorded;

                continue;
            }
            $path = $directory . '/' . $relative;
            $hash = ErrorTrap::run(static fn() => \hash_file('sha256', $path));
            if (!\is_string($hash) || $hash === '') {
                throw new \RuntimeException('Greenlight did not read artifact run content.');
            }
            $files[$relative] = ['bytes' => $size, 'sha256' => $hash];
        }

        return $files;
    }

    /**
     * Returns the size of each content file below a run directory. The
     * method uses `stat` data only and does not read file content.
     *
     * A directory without a content file is unowned, unless
     * $allowEmptyDirectories is true. An incomplete run can contain such a
     * directory when a publication failed after it created the directory.
     *
     * @return array<non-empty-string, int>
     * @throws \RuntimeException
     */
    public static function contentSizes(string $directory, bool $allowEmptyDirectories = false): array
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
        ) {
            throw new \RuntimeException('Artifact run root is not a directory owned by Greenlight.');
        }

        $files = [];
        $directories = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isLink()) {
                throw new \RuntimeException('Artifact run content contains a symbolic link.');
            }
            if (!$entry->isFile()) {
                if ($entry->isDir()) {
                    $directories[] = \substr($entry->getPathname(), \strlen($directory) + 1);

                    continue;
                }

                throw new \RuntimeException('Artifact run content contains an unsupported filesystem entry.');
            }
            $relative = \substr($entry->getPathname(), \strlen($directory) + 1);
            if (!RelativePath::isSafe($relative)) {
                throw new \RuntimeException('Artifact run content contains an unsafe path.');
            }
            if (\in_array($relative, [self::METADATA_FILE, self::LOCK_FILE], true)) {
                continue;
            }
            $size = $entry->getSize();
            if (!\is_int($size) || $size < 0) {
                throw new \RuntimeException('Greenlight did not read artifact run content.');
            }
            $files[$relative] = $size;
        }
        \ksort($files);
        $expectedDirectories = [];
        foreach (\array_keys($files) as $file) {
            $parent = \dirname($file);
            while ($parent !== '.') {
                $expectedDirectories[$parent] = true;
                $parent = \dirname($parent);
            }
        }
        \sort($directories);
        $expected = \array_keys($expectedDirectories);
        \sort($expected);
        if (!$allowEmptyDirectories && $directories !== $expected) {
            throw new \RuntimeException('Artifact run content contains an unowned directory.');
        }

        return $files;
    }

    /**
     * Adds the sizes of the metadata and lock files to $bytes.
     *
     * @throws \RuntimeException
     */
    public static function withInternalBytes(string $directory, int $bytes): int
    {
        foreach ([self::METADATA_FILE, self::LOCK_FILE] as $internal) {
            $size = ErrorTrap::run(static fn() => \filesize($directory . '/' . $internal));
            if (!\is_int($size) || $size < 0) {
                throw new \RuntimeException('Greenlight did not read artifact run metadata.');
            }
            $bytes = self::saturatingAdd($bytes, $size);
        }

        return $bytes;
    }

    /** @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $files */
    public static function manifestBytes(array $files): int
    {
        $bytes = 0;
        foreach ($files as $file) {
            $bytes = self::saturatingAdd($bytes, $file['bytes']);
        }

        return $bytes;
    }

    public static function saturatingAdd(int $left, int $right): int
    {
        return $left > \PHP_INT_MAX - $right ? \PHP_INT_MAX : $left + $right;
    }
}
