<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Resolves Greenlight storage once against the project working directory.
 * Explicit area directories replace the applicable directory below the root.
 *
 * @internal
 */
final readonly class StorageLayout
{
    private function __construct(
        public string $runStateFile,
        public string $cacheDirectory,
        public string $generatedCodeDirectory,
        public string $temporaryDirectory,
    ) {}

    /** @param non-empty-string|null $stateIdentity */
    public static function resolve(
        StorageConfiguration $configuration,
        string $workingDirectory,
        ?string $stateIdentity = null,
    ): self {
        $temporary = \rtrim(\sys_get_temp_dir(), '/');
        $root = self::configured($configuration->rootDirectory, $workingDirectory);
        $state = self::area($configuration->stateDirectory, $root, 'state', $workingDirectory);
        $cache = self::area($configuration->cacheDirectory, $root, 'cache', $workingDirectory);
        $generatedCode = self::area(
            $configuration->generatedCodeDirectory,
            $root,
            'generated-code',
            $workingDirectory,
        );
        $runtime = self::area($configuration->temporaryDirectory, $root, 'temporary', $workingDirectory);
        $projectKey = \substr(\sha1($workingDirectory), 0, 12);
        $stateFile = $stateIdentity === null ? 'run-state.json' : 'run-state-' . $stateIdentity . '.json';
        $temporaryStateSuffix = $stateIdentity === null ? '' : '-' . $stateIdentity;

        return new self(
            $state === null
                ? \sprintf('%s/greenlight-state-%s%s.json', $temporary, $projectKey, $temporaryStateSuffix)
                : $state . '/' . $stateFile,
            $cache ?? $temporary,
            $generatedCode ?? \sprintf('%s/greenlight-proxies-%s', $temporary, $projectKey),
            $runtime ?? $temporary,
        );
    }

    private static function area(
        ?string $configured,
        ?string $root,
        string $name,
        string $workingDirectory,
    ): ?string {
        if ($configured !== null) {
            return self::absolute($configured, $workingDirectory);
        }

        return $root === null ? null : \rtrim($root, '/') . '/' . $name;
    }

    private static function configured(?string $directory, string $workingDirectory): ?string
    {
        return $directory === null ? null : self::absolute($directory, $workingDirectory);
    }

    private static function absolute(string $directory, string $workingDirectory): string
    {
        $path = \str_starts_with($directory, '/')
            ? $directory
            : \rtrim($workingDirectory, '/') . '/' . $directory;

        $trimmed = \rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
