<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Runs PHP syntax, PHPStan, and Rector checks against generated examples.
 *
 * @internal
 */
final readonly class ToolAnalyser
{
    public function __construct(private ProcessRunner $processRunner = new ProcessRunner()) {}

    /**
     * @param list<MaterializedSnippet> $snippets
     *
     * @return list<Diagnostic>
     */
    public function syntax(string $root, array $snippets): array
    {
        $diagnostics = [];

        foreach ($snippets as $snippet) {
            $result = $this->processRunner->run(
                $root,
                [\PHP_BINARY, '-l', $root . '/' . $snippet->generatedPath],
            );

            if ($result->exitCode === 0) {
                continue;
            }

            $message = \trim($result->stderr !== '' ? $result->stderr : $result->stdout);
            $line = $snippet->source->sourceStartLine;

            if (\preg_match('/on line (\d+)/', $message, $match) === 1) {
                $line = $snippet->sourceLine((int) $match[1]) ?? $snippet->source->fenceLine;
            }

            $diagnostics[] = new Diagnostic(
                sourcePath: $snippet->source->sourcePath,
                line: $line,
                tool: 'PHP syntax',
                message: $message,
            );
        }

        return $diagnostics;
    }

    /**
     * @param list<MaterializedSnippet> $group
     *
     * @return list<Diagnostic>
     */
    public function phpStan(string $root, string $binary, array $group): array
    {
        if (!\in_array('phpstan', $group[0]->source->tools, true)) {
            return [];
        }

        $command = [
            ...$this->binaryCommand($binary),
            'analyse',
            '--configuration=' . $root . '/phpstan.docs.neon',
            '--error-format=json',
            '--no-progress',
            '--memory-limit=-1',
            $root . '/src/PhpStan',
            $root . '/build/docs-php/' . $group[0]->source->example,
        ];
        $result = $this->processRunner->run($root, $command);
        $payload = $this->jsonOutput('PHPStan', $result, $group[0]);
        $diagnostics = [];
        $files = $payload['files'] ?? [];

        if (!\is_array($files)) {
            throw DocumentationExampleError::invalidPhpStanFiles();
        }

        foreach ($files as $file => $fileResult) {
            if (!\is_string($file) || !\is_array($fileResult)) {
                throw DocumentationExampleError::invalidPhpStanFileResult();
            }

            $snippet = $this->generatedSnippet($root, $file, $group);
            $messages = $fileResult['messages'] ?? [];

            if (!\is_array($messages)) {
                throw DocumentationExampleError::invalidPhpStanMessages();
            }

            foreach ($messages as $message) {
                if (!\is_array($message) || !\is_string($message['message'] ?? null)) {
                    throw DocumentationExampleError::invalidPhpStanMessage();
                }

                $generatedLine = \is_int($message['line'] ?? null) ? $message['line'] : 1;
                $sourceLine = $snippet->sourceLine($generatedLine);
                $text = $message['message'];

                if ($sourceLine === null) {
                    $sourceLine = $snippet->source->fenceLine;
                    $text = 'Generated context reported an error: ' . $text;
                }

                $diagnostics[] = new Diagnostic(
                    sourcePath: $snippet->source->sourcePath,
                    line: $sourceLine,
                    tool: 'PHPStan',
                    message: $text,
                    identifier: \is_string($message['identifier'] ?? null) ? $message['identifier'] : null,
                );
            }
        }

        $globalErrors = $payload['errors'] ?? [];

        if (\is_array($globalErrors)) {
            foreach ($globalErrors as $error) {
                if (\is_string($error)) {
                    $diagnostics[] = new Diagnostic(
                        sourcePath: $group[0]->source->sourcePath,
                        line: $group[0]->source->fenceLine,
                        tool: 'PHPStan',
                        message: $error,
                    );
                }
            }
        }

        if ($result->exitCode !== 0 && $diagnostics === []) {
            throw DocumentationExampleError::phpStanFailedWithoutDiagnostic($result->stderr);
        }

        return $diagnostics;
    }

    /**
     * @param list<MaterializedSnippet> $snippets
     *
     * @return list<Diagnostic>
     */
    public function rector(string $root, string $binary, array $snippets): array
    {
        $selected = \array_values(\array_filter(
            $snippets,
            static fn(MaterializedSnippet $snippet): bool => \in_array(
                'rector',
                $snippet->source->tools,
                true,
            ),
        ));

        if ($selected === []) {
            return [];
        }

        $directories = [];

        foreach ($selected as $snippet) {
            $directories[$snippet->source->example] = $root . '/build/docs-php/' . $snippet->source->example;
        }

        \ksort($directories, \SORT_STRING);
        $command = [
            ...$this->binaryCommand($binary),
            'process',
            ...\array_values($directories),
            '--config=' . $root . '/rector.docs.php',
            '--dry-run',
            '--output-format=json',
            '--no-progress-bar',
        ];
        $result = $this->processRunner->run($root, $command);
        $payload = $this->jsonOutput('Rector', $result, $selected[0]);
        $diagnostics = [];
        $fatalErrors = $payload['fatal_errors'] ?? [];

        if (!\is_array($fatalErrors)) {
            throw DocumentationExampleError::invalidRectorFatalErrors();
        }

        foreach ($fatalErrors as $fatalError) {
            if (!\is_string($fatalError)) {
                throw DocumentationExampleError::invalidRectorFatalError();
            }

            $diagnostics[] = new Diagnostic(
                sourcePath: $selected[0]->source->sourcePath,
                line: $selected[0]->source->fenceLine,
                tool: 'Rector',
                message: $fatalError,
            );
        }

        $fileDiffs = $payload['file_diffs'] ?? [];

        if (!\is_array($fileDiffs)) {
            throw DocumentationExampleError::invalidRectorFileDiffs();
        }

        foreach ($fileDiffs as $fileDiff) {
            if (!\is_array($fileDiff) || !\is_string($fileDiff['file'] ?? null)) {
                throw DocumentationExampleError::invalidRectorFileDiff();
            }

            $snippet = $this->generatedSnippet($root, $fileDiff['file'], $selected);
            $rectors = $fileDiff['applied_rectors'] ?? [];
            $names = [];

            if (\is_array($rectors)) {
                foreach ($rectors as $rector) {
                    if (\is_string($rector)) {
                        $names[] = \str_contains($rector, '\\')
                            ? \substr($rector, (int) \strrpos($rector, '\\') + 1)
                            : $rector;
                    }
                }
            }

            \sort($names, \SORT_STRING);
            $message = $names === []
                ? 'Rector would change this example.'
                : \sprintf('Rector would change this example. Applied rules: %s.', \implode(', ', $names));
            $generatedLine = $this->firstChangedLine($fileDiff['diff'] ?? null);
            $sourceLine = $generatedLine === null ? null : $snippet->sourceLine($generatedLine);
            $diagnostics[] = new Diagnostic(
                sourcePath: $snippet->source->sourcePath,
                line: $sourceLine ?? $snippet->source->fenceLine,
                tool: 'Rector',
                message: $message,
            );
        }

        $totals = $payload['totals'] ?? null;

        if (\is_array($totals) && \is_int($totals['errors'] ?? null) && $totals['errors'] > 0) {
            $diagnostics[] = new Diagnostic(
                sourcePath: $selected[0]->source->sourcePath,
                line: $selected[0]->source->fenceLine,
                tool: 'Rector',
                message: \sprintf('Rector reported %d processing error(s).', $totals['errors']),
            );
        }

        if ($result->exitCode !== 0 && $diagnostics === []) {
            throw DocumentationExampleError::rectorFailedWithoutChange($result->stderr);
        }

        return $diagnostics;
    }

    private function firstChangedLine(mixed $diff): ?int
    {
        if (!\is_string($diff)
            || \preg_match('/^@@ -(\d+)(?:,\d+)? \+\d+(?:,\d+)? @@/m', $diff, $match) !== 1
        ) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @return non-empty-list<string>
     */
    private function binaryCommand(string $binary): array
    {
        if (!\is_file($binary)) {
            throw DocumentationExampleError::toolNotFound($binary);
        }

        return \str_ends_with($binary, '.php') ? [\PHP_BINARY, $binary] : [$binary];
    }

    /**
     * @param list<MaterializedSnippet> $group
     */
    private function generatedSnippet(string $root, string $file, array $group): MaterializedSnippet
    {
        $normalized = \str_replace('\\', '/', $file);

        foreach ($group as $snippet) {
            $relative = $snippet->generatedPath;
            $absolute = \str_replace('\\', '/', $root . '/' . $relative);

            if ($normalized === $relative || $normalized === $absolute || \str_ends_with($normalized, '/' . $relative)) {
                return $snippet;
            }
        }

        throw DocumentationExampleError::unknownGeneratedFile($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(
        string $tool,
        ProcessResult $result,
        MaterializedSnippet $fallback,
    ): array {
        try {
            $payload = \json_decode($result->stdout, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw DocumentationExampleError::invalidToolJson($tool, $fallback->source->example, $error, $result->stderr);
        }

        if (!\is_array($payload) || \array_is_list($payload)) {
            throw DocumentationExampleError::toolOutputNotAnObject($tool);
        }

        $validated = [];

        foreach ($payload as $field => $value) {
            if (!\is_string($field)) {
                throw DocumentationExampleError::invalidToolFieldName($tool);
            }

            $validated[$field] = $value;
        }

        return $validated;
    }
}
