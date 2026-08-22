<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * Builds PHP commands that load Greenlight directly from its source tree.
 */
final readonly class SourceOnlyPhp
{
    private const string AUTOLOAD = <<<'PHP'
        $source = %s;

        spl_autoload_register(static function (string $class) use ($source): void {
            $prefix = 'Greenlight\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $path = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
        PHP;

    private function __construct() {}

    /**
     * @param non-empty-string $sourceDirectory
     * @param non-empty-string $program
     * @param list<string> $phpOptions
     * @param list<string> $arguments
     *
     * @return non-empty-list<string>
     */
    public static function command(
        string $sourceDirectory,
        string $program,
        array $phpOptions = [],
        array $arguments = [],
    ): array {
        return [
            \PHP_BINARY,
            '-n',
            ...$phpOptions,
            '-r',
            \sprintf(self::AUTOLOAD, \var_export($sourceDirectory, true)) . "\n" . $program,
            ...$arguments,
        ];
    }
}
