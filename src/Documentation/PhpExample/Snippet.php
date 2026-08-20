<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Defines one PHP fence and its analysis contract.
 *
 * @internal
 */
final readonly class Snippet
{
    /**
     * @param list<'phpstan'|'rector'> $tools
     */
    public function __construct(
        public string $sourcePath,
        public int $fenceLine,
        public int $sourceStartLine,
        public int $sourceEndLine,
        public string $body,
        public string $example,
        public string $virtualFile,
        public string $mode,
        public array $tools,
    ) {}
}
