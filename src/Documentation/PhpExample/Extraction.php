<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Contains the selected snippets and the complete PHP fence inventory.
 *
 * @internal
 */
final readonly class Extraction
{
    /**
     * @param list<Snippet> $snippets
     */
    public function __construct(
        public array $snippets,
        public int $phpFences,
        public int $unclassifiedFences,
        public int $displayFences,
        public int $generatedDocuments,
    ) {}
}
