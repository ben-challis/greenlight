<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Matches watch paths with case-sensitive, slash-separated glob patterns.
 *
 * @internal
 */
final readonly class WatchPathMatcher
{
    /** @var list<array{regex: non-empty-string, absolute: bool}> */
    private array $includes;

    /** @var list<array{regex: non-empty-string, absolute: bool}> */
    private array $excludes;

    /** @var list<array{regex: non-empty-string, absolute: bool}> */
    private array $excludedDirectories;

    /**
     * @param list<non-empty-string> $includePatterns
     * @param list<non-empty-string> $excludePatterns
     */
    public function __construct(
        private string $workingDirectory,
        array $includePatterns,
        array $excludePatterns,
    ) {
        $this->includes = $this->compile($includePatterns);
        $this->excludes = $this->compile($excludePatterns);
        /** @var list<non-empty-string> $directoryPatterns */
        $directoryPatterns = [];

        foreach ($excludePatterns as $pattern) {
            $normalized = $this->normalize($pattern);

            if (\str_ends_with($normalized, '/**')) {
                $prefix = \substr($normalized, 0, -3);
                $directoryPatterns[] = $prefix === '' ? '/' : $prefix;
            }
        }

        $this->excludedDirectories = $this->compile($directoryPatterns);
    }

    public function includesAdditionalFile(string $path, bool $explicit): bool
    {
        if ($this->isExcluded($path)) {
            return false;
        }

        return $explicit || $this->includes === [] || $this->matches($path, $this->includes);
    }

    public function includesDefaultPhpFile(string $path): bool
    {
        return \pathinfo($path, \PATHINFO_EXTENSION) === 'php'
            && !$this->isExcluded($path);
    }

    public function isExcluded(string $path): bool
    {
        return $this->matches($path, $this->excludes);
    }

    public function excludesDirectory(string $path): bool
    {
        return $this->matches($path, $this->excludedDirectories);
    }

    /**
     * @param list<non-empty-string> $patterns
     * @return list<array{regex: non-empty-string, absolute: bool}>
     */
    private function compile(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $pattern) {
            $normalized = $this->normalize($pattern);
            $regex = '';
            $length = \strlen($normalized);

            for ($offset = 0; $offset < $length; ++$offset) {
                $character = $normalized[$offset];

                if ($character === '*' && ($normalized[$offset + 1] ?? '') === '*') {
                    ++$offset;

                    if (($normalized[$offset + 1] ?? '') === '/') {
                        ++$offset;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }

                    continue;
                }

                $regex .= match ($character) {
                    '*' => '[^/]*',
                    '?' => '[^/]',
                    default => \preg_quote($character, '~'),
                };
            }

            $absolute = $this->isAbsolute($normalized);
            $compiled[] = [
                'regex' => '~\\A' . $regex . '\\z~D',
                'absolute' => $absolute,
            ];
        }

        return $compiled;
    }

    /**
     * @param list<array{regex: non-empty-string, absolute: bool}> $patterns
     */
    private function matches(string $path, array $patterns): bool
    {
        $absolute = $this->normalize($path);
        $relative = $this->relative($absolute);

        return \array_any(
            $patterns,
            static fn(array $pattern): bool => \preg_match(
                $pattern['regex'],
                $pattern['absolute'] ? $absolute : $relative,
            ) === 1,
        );
    }

    private function relative(string $path): string
    {
        $root = \rtrim($this->normalize($this->workingDirectory), '/');

        if ($path === $root) {
            return '';
        }

        $prefix = $root . '/';

        return \str_starts_with($path, $prefix) ? \substr($path, \strlen($prefix)) : $path;
    }

    private function normalize(string $path): string
    {
        $normalized = \str_replace('\\', '/', $path);

        while (\str_starts_with($normalized, './')) {
            $normalized = \substr($normalized, 2);
        }

        return $normalized;
    }

    private function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/')
            || \preg_match('~\\A[A-Za-z]:/~D', $path) === 1;
    }
}
