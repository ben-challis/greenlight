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

    public function __construct(private readonly ?string $temporaryRoot = null) {}

    public function path(): string
    {
        if ($this->path === null) {
            $systemTemporaryRoot = $this->temporaryRoot ?? \sys_get_temp_dir();
            $resolvedTemporaryRoot = \realpath($systemTemporaryRoot);
            $temporaryRoot = $resolvedTemporaryRoot === false ? $systemTemporaryRoot : $resolvedTemporaryRoot;
            $path = $temporaryRoot . '/greenlight-' . \bin2hex(\random_bytes(8));

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
     * @param string $name A relative path of plain segments. The path can
     *   contain separators but cannot contain traversal segments.
     *
     * @return non-empty-string
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
        if ($this->path === null) {
            return;
        }

        $path = $this->path;

        if (\is_link($path)) {
            if (!ErrorTrap::run(static fn(): bool => \unlink($path), $warning)) {
                throw new \RuntimeException(\sprintf(
                    'Failed to remove temp directory symbolic link "%s"%s.',
                    $path,
                    $warning === null ? '' : ': ' . $warning,
                ));
            }

            $this->path = null;

            return;
        }

        if (!\is_dir($path)) {
            $this->path = null;

            return;
        }

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

        $this->path = null;
    }
}
