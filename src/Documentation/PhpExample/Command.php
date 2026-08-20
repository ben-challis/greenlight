<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Provides the command-line interface for documentation PHP checks.
 *
 * @internal
 */
final readonly class Command
{
    /**
     * @param list<string> $arguments
     */
    public static function run(array $arguments): int
    {
        try {
            $options = self::options($arguments);
            $checker = new Checker();

            if ($options['command'] === 'extract') {
                $result = $checker->extract($options['root']);
                echo \sprintf(
                    'Extracted %d PHP file(s) from %d classified fence(s).',
                    \count($result->materialized),
                    \count($result->extraction->snippets) + $result->extraction->displayFences,
                ), "\n";

                return 0;
            }

            $result = $checker->check($options['root'], $options['phpstan'], $options['rector']);
            self::renderDiagnostics($result->diagnostics);
            echo \sprintf(
                'Documentation PHP: %d fence(s), %d checked file(s), %d PHPStan file(s), %d Rector file(s), %d display-only fence(s), %d generated document(s).',
                $result->extraction->phpFences,
                \count($result->materialized),
                self::toolFileCount($result->materialized, 'phpstan'),
                self::toolFileCount($result->materialized, 'rector'),
                $result->extraction->displayFences,
                $result->extraction->generatedDocuments,
            ), "\n";

            return $result->diagnostics === [] ? 0 : 1;
        } catch (DocumentationExampleError | \JsonException $error) {
            \fwrite(\STDERR, $error->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<MaterializedSnippet> $snippets
     */
    private static function toolFileCount(array $snippets, string $tool): int
    {
        return \count(\array_filter(
            $snippets,
            static fn(MaterializedSnippet $snippet): bool => \in_array($tool, $snippet->source->tools, true),
        ));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{command: 'check'|'extract', root: string, phpstan: string, rector: string}
     */
    private static function options(array $arguments): array
    {
        $command = $arguments[1] ?? 'check';

        if (!\in_array($command, ['check', 'extract'], true)) {
            throw new DocumentationExampleError(\sprintf(
                'Unknown documentation PHP command "%s".',
                $command,
            ));
        }

        $root = \getcwd();

        if ($root === false) {
            throw new DocumentationExampleError('Cannot identify the current directory.');
        }

        $phpstan = null;
        $rector = null;

        foreach (\array_slice($arguments, 2) as $argument) {
            if (\str_starts_with($argument, '--root=')) {
                $root = \substr($argument, \strlen('--root='));
            } elseif (\str_starts_with($argument, '--phpstan-bin=')) {
                $phpstan = \substr($argument, \strlen('--phpstan-bin='));
            } elseif (\str_starts_with($argument, '--rector-bin=')) {
                $rector = \substr($argument, \strlen('--rector-bin='));
            } else {
                throw new DocumentationExampleError(\sprintf(
                    'Unknown documentation PHP option "%s".',
                    $argument,
                ));
            }
        }

        $realRoot = \realpath($root);

        if ($realRoot === false || !\is_dir($realRoot)) {
            throw new DocumentationExampleError(\sprintf(
                'Documentation PHP root "%s" does not exist.',
                $root,
            ));
        }

        return [
            'command' => $command,
            'root' => $realRoot,
            'phpstan' => $phpstan ?? $realRoot . '/vendor/bin/phpstan',
            'rector' => $rector ?? $realRoot . '/vendor/bin/rector',
        ];
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private static function renderDiagnostics(array $diagnostics): void
    {
        \usort(
            $diagnostics,
            static fn(Diagnostic $left, Diagnostic $right): int => [
                $left->sourcePath,
                $left->line,
                $left->tool,
                $left->identifier ?? '',
                $left->message,
            ] <=> [
                $right->sourcePath,
                $right->line,
                $right->tool,
                $right->identifier ?? '',
                $right->message,
            ],
        );

        $github = \getenv('GITHUB_ACTIONS') === 'true';

        foreach ($diagnostics as $diagnostic) {
            $label = $diagnostic->tool;

            if ($diagnostic->identifier !== null) {
                $label .= ' [' . $diagnostic->identifier . ']';
            }

            if ($github) {
                $message = self::workflowEscape($label . ': ' . $diagnostic->message);
                $path = self::workflowEscape($diagnostic->sourcePath);
                echo \sprintf(
                    '::error file=%s,line=%d::%s',
                    $path,
                    $diagnostic->line,
                    $message,
                ), "\n";

                continue;
            }

            echo \sprintf(
                '%s:%d: %s: %s',
                $diagnostic->sourcePath,
                $diagnostic->line,
                $label,
                $diagnostic->message,
            ), "\n";
        }
    }

    private static function workflowEscape(string $value): string
    {
        return \str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }
}
