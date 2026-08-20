<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Core\ErrorTrap;
use Greenlight\Harness\Disposable;

/**
 * Creates one root directory on first use. A path inside it cannot escape the
 * root.
 * Disposal removes a symbolic link and leaves its target unchanged.
 */
final class TempDirectory implements Disposable
{
    private ?string $path = null;

    private readonly ?string $temporaryRoot;

    /** @throws \InvalidArgumentException If $temporaryRoot contains a null byte. */
    public function __construct(?string $temporaryRoot = null)
    {
        if ($temporaryRoot !== null && \str_contains($temporaryRoot, "\0")) {
            throw new \InvalidArgumentException('Temporary root MUST NOT contain a null byte.');
        }

        $this->temporaryRoot = $temporaryRoot;
    }

    /** @throws TempDirectoryError */
    public function path(): string
    {
        if ($this->path === null) {
            $systemTemporaryRoot = $this->temporaryRoot ?? \sys_get_temp_dir();
            $resolvedTemporaryRoot = ErrorTrap::run(static fn() => \realpath($systemTemporaryRoot));
            $temporaryRoot = $resolvedTemporaryRoot === false ? $systemTemporaryRoot : $resolvedTemporaryRoot;
            $path = $temporaryRoot . '/greenlight-' . \bin2hex(\random_bytes(8));

            if (!ErrorTrap::run(static fn() => \mkdir($path, 0700), $warning)) {
                throw TempDirectoryError::rootCreationFailed($path, $warning);
            }

            $this->path = $path;
        }

        return $this->path;
    }

    /**
     * @param string $name A relative path of plain segments. The path can
     *   contain separators but cannot contain traversal segments.
     *
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException
     * @throws TempDirectoryError
     */
    public function subdirectory(string $name): string
    {
        if (\str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Subdirectory name MUST NOT contain a null byte.');
        }

        if ($name === '' || \str_starts_with($name, '/') || \str_contains($name, '\\')) {
            throw new \InvalidArgumentException(\sprintf('Subdirectory name "%s" must be a relative path.', $name));
        }

        foreach (\explode('/', $name) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new \InvalidArgumentException(\sprintf('Subdirectory name "%s" must not contain empty or traversal segments.', $name));
            }
        }

        $root = $this->path();
        $prefix = $root;
        $this->assertNotSymbolicLink($prefix);

        foreach (\explode('/', $name) as $segment) {
            $prefix .= '/' . $segment;
            $this->assertNotSymbolicLink($prefix);

            if (!\file_exists($prefix)) {
                break;
            }
        }

        $path = $root . '/' . $name;

        if (!\is_dir($path) && !ErrorTrap::run(static fn() => \mkdir($path, 0700, true), $warning)) {
            throw TempDirectoryError::subdirectoryCreationFailed($path, $warning);
        }

        return $path;
    }

    /** @throws TempDirectoryError */
    private function assertNotSymbolicLink(string $path): void
    {
        if (\is_link($path)) {
            throw TempDirectoryError::symbolicLink($path);
        }
    }

    /** @throws TempDirectoryError */
    #[\Override]
    public function dispose(): void
    {
        if ($this->path === null) {
            return;
        }

        $path = $this->path;
        $isLink = ErrorTrap::run(static fn() => \is_link($path), $warning);

        if ($warning !== null) {
            throw TempDirectoryError::rootRemovalFailed($path, $warning);
        }

        if ($isLink) {
            if (!ErrorTrap::run(static fn() => \unlink($path), $warning)) {
                throw TempDirectoryError::rootLinkRemovalFailed($path, $warning);
            }

            $this->path = null;

            return;
        }

        $isDirectory = ErrorTrap::run(static fn() => \is_dir($path), $warning);

        if ($warning !== null) {
            throw TempDirectoryError::rootRemovalFailed($path, $warning);
        }

        if (!$isDirectory) {
            $this->path = null;

            return;
        }

        ErrorTrap::run(
            static function () use ($path) {
                $entries = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );

                /** @var \SplFileInfo $entry */
                foreach ($entries as $entry) {
                    $pathname = $entry->getPathname();
                    $removed = !$entry->isLink() && $entry->isDir() ? \rmdir($pathname) : \unlink($pathname);

                    if (!$removed) {
                        throw TempDirectoryError::entryRemovalFailed($pathname, $path);
                    }
                }
            },
            wrap: static fn(\Throwable $error): \Throwable => $error instanceof \UnexpectedValueException
                ? TempDirectoryError::rootRemovalFailed($path, $error->getMessage())
                : $error,
        );

        if (!ErrorTrap::run(static fn() => \rmdir($path), $warning)) {
            throw TempDirectoryError::rootRemovalFailed($path, $warning);
        }

        $this->path = null;
    }
}
