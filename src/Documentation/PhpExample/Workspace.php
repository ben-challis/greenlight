<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Materializes snippets and publishes their deterministic generated workspace.
 *
 * @internal
 */
final readonly class Workspace
{
    private const int MANIFEST_VERSION = 1;

    /**
     * @param list<Snippet> $snippets
     *
     * @return list<MaterializedSnippet>
     */
    public function publish(string $root, array $snippets): array
    {
        $materialized = \array_map($this->materialize(...), $snippets);
        $destination = $root . '/build/docs-php';
        $staging = $root . '/build/docs-php.next-' . \getmypid();
        $this->removeDirectory($staging, $root . '/build');

        if (!\is_dir($staging) && !\mkdir($staging, 0o777, true) && !\is_dir($staging)) {
            throw new DocumentationExampleError(\sprintf(
                'Cannot create generated directory "%s".',
                $staging,
            ));
        }

        foreach ($materialized as $snippet) {
            $relative = \substr($snippet->generatedPath, \strlen('build/docs-php/'));
            $path = $staging . '/' . $relative;
            $parent = \dirname($path);

            if (!\is_dir($parent) && !\mkdir($parent, 0o777, true) && !\is_dir($parent)) {
                throw new DocumentationExampleError(\sprintf(
                    'Cannot create generated directory "%s".',
                    $parent,
                ));
            }

            if (\file_put_contents($path, $snippet->contents) !== \strlen($snippet->contents)) {
                throw new DocumentationExampleError(\sprintf(
                    'Cannot write generated file "%s".',
                    $path,
                ));
            }
        }

        $manifest = \json_encode(
            $this->manifest($materialized),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . "\n";

        if (\file_put_contents($staging . '/manifest.json', $manifest) !== \strlen($manifest)) {
            throw new DocumentationExampleError('Cannot write the documentation PHP manifest.');
        }

        $this->removeDirectory($destination, $root . '/build');

        if (!\rename($staging, $destination)) {
            throw new DocumentationExampleError('Cannot publish the generated documentation PHP directory.');
        }

        return $materialized;
    }

    private function materialize(Snippet $snippet): MaterializedSnippet
    {
        $generatedPath = 'build/docs-php/' . $snippet->example . '/' . $snippet->virtualFile;
        $sourceLines = \substr_count($snippet->body, "\n");

        if ($snippet->mode === 'file') {
            $hasOpeningTag = \preg_match('/\A\s*<\?php\b/', $snippet->body) === 1;
            $prefix = $hasOpeningTag ? '' : "<?php\n";
            $generatedStartLine = $hasOpeningTag ? 1 : 2;
            $syntheticRanges = $hasOpeningTag ? [] : [['startLine' => 1, 'endLine' => 1]];

            return new MaterializedSnippet(
                source: $snippet,
                generatedPath: $generatedPath,
                contents: $prefix . $snippet->body,
                generatedStartLine: $generatedStartLine,
                generatedEndLine: $generatedStartLine + $sourceLines - 1,
                syntheticRanges: $syntheticRanges,
            );
        }

        $indented = $this->indent($snippet->body);

        if ($snippet->mode === 'statements') {
            $prefix = "<?php\n\n(static function (): void {\n";
            $generatedStartLine = 4;
            $generatedEndLine = $generatedStartLine + $sourceLines - 1;

            return new MaterializedSnippet(
                source: $snippet,
                generatedPath: $generatedPath,
                contents: $prefix . $indented . "})();\n",
                generatedStartLine: $generatedStartLine,
                generatedEndLine: $generatedEndLine,
                syntheticRanges: [
                    ['startLine' => 1, 'endLine' => 3],
                    ['startLine' => $generatedEndLine + 1, 'endLine' => $generatedEndLine + 1],
                ],
            );
        }

        $class = 'DocsExample_' . \substr(\sha1($snippet->example . '/' . $snippet->virtualFile), 0, 12);
        $prefix = "<?php\n\nfinal class {$class}\n{\n";
        $generatedStartLine = 5;
        $generatedEndLine = $generatedStartLine + $sourceLines - 1;

        return new MaterializedSnippet(
            source: $snippet,
            generatedPath: $generatedPath,
            contents: $prefix . $indented . "}\n",
            generatedStartLine: $generatedStartLine,
            generatedEndLine: $generatedEndLine,
            syntheticRanges: [
                ['startLine' => 1, 'endLine' => 4],
                ['startLine' => $generatedEndLine + 1, 'endLine' => $generatedEndLine + 1],
            ],
        );
    }

    private function indent(string $body): string
    {
        $lines = \explode("\n", $body);
        $indented = '';

        foreach ($lines as $index => $line) {
            if ($index === \array_key_last($lines) && $line === '') {
                continue;
            }

            $indented .= '    ' . $line . "\n";
        }

        return $indented;
    }

    /**
     * @param list<MaterializedSnippet> $snippets
     *
     * @return array{version: int, snippets: list<array<string, mixed>>}
     */
    private function manifest(array $snippets): array
    {
        $entries = [];

        foreach ($snippets as $snippet) {
            $entries[] = [
                'example' => $snippet->source->example,
                'virtualFile' => $snippet->source->virtualFile,
                'mode' => $snippet->source->mode,
                'tools' => $snippet->source->tools,
                'generatedPath' => $snippet->generatedPath,
                'source' => [
                    'path' => $snippet->source->sourcePath,
                    'fenceLine' => $snippet->source->fenceLine,
                    'startLine' => $snippet->source->sourceStartLine,
                    'endLine' => $snippet->source->sourceEndLine,
                    'sha256' => \hash('sha256', $snippet->source->body),
                ],
                'mappings' => [[
                    'generatedStartLine' => $snippet->generatedStartLine,
                    'generatedEndLine' => $snippet->generatedEndLine,
                    'sourceStartLine' => $snippet->source->sourceStartLine,
                ]],
                'syntheticRanges' => $snippet->syntheticRanges,
            ];
        }

        return ['version' => self::MANIFEST_VERSION, 'snippets' => $entries];
    }

    private function removeDirectory(string $directory, string $allowedParent): void
    {
        if (!\file_exists($directory)) {
            return;
        }

        $normalizedDirectory = \str_replace('\\', '/', $directory);
        $normalizedParent = \rtrim(\str_replace('\\', '/', $allowedParent), '/');

        if (!\str_starts_with($normalizedDirectory, $normalizedParent . '/docs-php')) {
            throw new DocumentationExampleError(\sprintf(
                'Refusing to remove unexpected directory "%s".',
                $directory,
            ));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new DocumentationExampleError('Generated directory contains an unknown file entry.');
            }

            if ($item->isDir()) {
                if (!\rmdir($item->getPathname())) {
                    throw new DocumentationExampleError(\sprintf(
                        'Cannot remove generated directory "%s".',
                        $item->getPathname(),
                    ));
                }
            } elseif (!\unlink($item->getPathname())) {
                throw new DocumentationExampleError(\sprintf(
                    'Cannot remove generated file "%s".',
                    $item->getPathname(),
                ));
            }
        }

        if (!\rmdir($directory)) {
            throw new DocumentationExampleError(\sprintf(
                'Cannot remove generated directory "%s".',
                $directory,
            ));
        }
    }
}
