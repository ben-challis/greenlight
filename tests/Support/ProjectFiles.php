<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\ErrorTrap;
use Greenlight\Fixture\TempDirectory;

/** Owns validated, fail-fast file writes inside one test project. */
final readonly class ProjectFiles
{
    public function __construct(
        public string $directory,
        private string $description = 'test project',
    ) {}

    public static function create(
        TempDirectory $workspace,
        string $name,
        string $description = 'test project',
    ): self {
        return new self($workspace->subdirectory($name), $description);
    }

    public function path(string $relative): string
    {
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \str_contains($relative, '\\')
            || \str_contains($relative, "\0")
        ) {
            throw $this->invalidPath($relative);
        }

        foreach (\explode('/', $relative) as $segment) {
            if (\in_array($segment, ['', '.', '..'], true)) {
                throw $this->invalidPath($relative);
            }
        }

        return $this->directory . '/' . $relative;
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath);
        $parent = \dirname($path);

        if (!\is_dir($parent)
            && !ErrorTrap::run(static fn(): bool => \mkdir($parent, 0o777, true), $warning)
            && !\is_dir($parent)
        ) {
            throw new \RuntimeException(\sprintf(
                'Failed to create %s directory "%s"%s.',
                $this->description,
                $parent,
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        $written = ErrorTrap::run(
            static fn(): int|false => \file_put_contents($path, $contents),
            $warning,
        );

        if ($written !== \strlen($contents)) {
            throw new \RuntimeException(\sprintf(
                'Failed to write %s file "%s"%s.',
                $this->description,
                $path,
                $warning === null ? '' : ': ' . $warning,
            ));
        }
    }

    private function invalidPath(string $relative): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            '%s path "%s" must be a relative path of plain segments.',
            \ucfirst($this->description),
            $relative,
        ));
    }
}
