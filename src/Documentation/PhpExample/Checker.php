<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Coordinates extraction, workspace publication, and analysis tools.
 *
 * @internal
 */
final readonly class Checker
{
    public function __construct(
        private Extractor $extractor = new Extractor(),
        private Workspace $workspace = new Workspace(),
        private ToolAnalyser $toolAnalyser = new ToolAnalyser(),
    ) {}

    public function extract(string $root): CheckResult
    {
        $extraction = $this->extractor->extract($root);
        $materialized = $this->workspace->publish($root, $extraction->snippets);

        return new CheckResult($extraction, $materialized, []);
    }

    public function check(string $root, string $phpstan, string $rector): CheckResult
    {
        $extraction = $this->extractor->extract($root);
        $materialized = $this->workspace->publish($root, $extraction->snippets);
        $diagnostics = $this->toolAnalyser->syntax($root, $materialized);

        if ($diagnostics === []) {
            foreach ($this->groups($materialized) as $group) {
                $diagnostics = [
                    ...$diagnostics,
                    ...$this->toolAnalyser->phpStan($root, $phpstan, $group),
                ];
            }

            $diagnostics = [
                ...$diagnostics,
                ...$this->toolAnalyser->rector($root, $rector, $materialized),
            ];
        }

        return new CheckResult($extraction, $materialized, $diagnostics);
    }

    /**
     * @param list<MaterializedSnippet> $snippets
     *
     * @return array<string, list<MaterializedSnippet>>
     */
    private function groups(array $snippets): array
    {
        $groups = [];

        foreach ($snippets as $snippet) {
            $groups[$snippet->source->example][] = $snippet;
        }

        \ksort($groups, \SORT_STRING);

        return $groups;
    }
}
