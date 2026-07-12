<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Core\ErrorTrap;
use Greenlight\Harness\Disposable;

/**
 * A per-test scratch directory that cleans up after itself.
 *
 * Nothing touches the disk until path() is first called; it then creates and
 * memoizes a unique directory under the system temp dir, so parallel workers
 * never collide. subdirectory() creates nested directories under it and
 * rejects names that would escape the root. dispose() removes everything
 * recursively without following symlinks and fails loudly if removal fails.
 */
final class TempDirectory implements Disposable
{
    private ?string $path = null;

    public function path(): string
    {
        if ($this->path === null) {
            $path = \sys_get_temp_dir() . '/greenlight-' . \bin2hex(\random_bytes(8));

            if (!ErrorTrap::run(static fn(): bool => \mkdir($path, 0700), $warning)) {
                throw new \RuntimeException(\sprintf(
                    'Failed to create temp directory "%s"%s.',
                    $path,
                    $warning === null ? '' : ': ' . $warning,
                ));
            }

            $this->path = $path;
        }

        return $this->path;
    }

    /**
     * @param string $name a relative path of plain segments; separators are allowed, traversal is not
     */
    public function subdirectory(string $name): string
    {
        if ($name === '' || \str_starts_with($name, '/') || \str_contains($name, '\\')) {
            throw new \InvalidArgumentException(\sprintf('Subdirectory name "%s" must be a relative path.', $name));
        }

        foreach (\explode('/', $name) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new \InvalidArgumentException(\sprintf('Subdirectory name "%s" must not contain empty or traversal segments.', $name));
            }
        }

        $path = $this->path() . '/' . $name;

        if (!\is_dir($path) && !ErrorTrap::run(static fn(): bool => \mkdir($path, 0700, true), $warning)) {
            throw new \RuntimeException(\sprintf(
                'Failed to create subdirectory "%s"%s.',
                $path,
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        return $path;
    }

    #[\Override]
    public function dispose(): void
    {
        if ($this->path === null || !\is_dir($this->path)) {
            return;
        }

        $path = $this->path;

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        ErrorTrap::run(static function () use ($entries, $path): void {
            /** @var \SplFileInfo $entry */
            foreach ($entries as $entry) {
                $pathname = $entry->getPathname();
                $removed = !$entry->isLink() && $entry->isDir() ? \rmdir($pathname) : \unlink($pathname);

                if (!$removed) {
                    throw new \RuntimeException(\sprintf('Failed to remove "%s" while disposing temp directory "%s".', $pathname, $path));
                }
            }
        });

        if (!ErrorTrap::run(static fn(): bool => \rmdir($path), $warning)) {
            throw new \RuntimeException(\sprintf(
                'Failed to remove temp directory "%s"%s.',
                $path,
                $warning === null ? '' : ': ' . $warning,
            ));
        }
    }
}
