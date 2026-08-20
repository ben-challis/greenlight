<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Contains one generated PHP file and its source-line mapping.
 *
 * @internal
 */
final readonly class MaterializedSnippet
{
    /**
     * @param list<array{startLine: int, endLine: int}> $syntheticRanges
     */
    public function __construct(
        public Snippet $source,
        public string $generatedPath,
        public string $contents,
        public int $generatedStartLine,
        public int $generatedEndLine,
        public array $syntheticRanges,
    ) {}

    public function sourceLine(int $generatedLine): ?int
    {
        foreach ($this->syntheticRanges as $range) {
            if ($generatedLine >= $range['startLine'] && $generatedLine <= $range['endLine']) {
                return null;
            }
        }

        if ($generatedLine < $this->generatedStartLine || $generatedLine > $this->generatedEndLine) {
            return null;
        }

        return $this->source->sourceStartLine + $generatedLine - $this->generatedStartLine;
    }
}
