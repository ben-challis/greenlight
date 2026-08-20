<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Contains the complete result of one documentation example check.
 *
 * @internal
 */
final readonly class CheckResult
{
    /**
     * @param list<MaterializedSnippet> $materialized
     * @param list<Diagnostic>          $diagnostics
     */
    public function __construct(
        public Extraction $extraction,
        public array $materialized,
        public array $diagnostics,
    ) {}
}
