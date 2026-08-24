<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection;

use Greenlight\Coverage\BranchCoverage;
use Greenlight\Coverage\BranchExitCoverage;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\FunctionCoverage;
use Greenlight\Coverage\PathCoverage;

/**
 * Contains a normalized map of line numbers to driver status values for each file.
 * A positive value means that the line executed. Minus one means uncovered.
 * Minus two means dead code.
 *
 * CoverageMap conversion removes dead code.
 *
 * @internal
 */
final readonly class RawCoverage
{
    /**
     * @var array<string, array<int, int>>
     */
    public array $lines;

    /** @var array<string, list<FunctionCoverage>> */
    public array $functions;

    /**
     * @param array<mixed> $lines Raw extension output.
     */
    public function __construct(array $lines, public bool $branchCoverage = false)
    {
        $normalized = [];
        $functions = [];

        foreach ($lines as $path => $fileData) {
            if (!\is_string($path) || !\is_array($fileData)) {
                continue;
            }

            $fileLines = $branchCoverage ? ($fileData['lines'] ?? null) : $fileData;

            if (!\is_array($fileLines)) {
                continue;
            }

            $statuses = [];

            foreach ($fileLines as $line => $status) {
                if (\is_int($line) && \is_int($status)) {
                    $statuses[$line] = $status;
                }
            }

            $normalized[$path] = $statuses;

            if ($branchCoverage) {
                $functions[$path] = $this->normalizeFunctions($fileData['functions'] ?? null);
            }
        }

        $this->lines = $normalized;
        $this->functions = $functions;
    }

    public static function none(bool $branchCoverage = false): self
    {
        return new self([], $branchCoverage);
    }

    /**
     * Converts raw driver output to a coverage map. It removes dead code
     * lines and files that the path filter rejects. A positive status becomes
     * covered. A status of minus one becomes uncovered.
     */
    public function toMap(?PathFilter $filter = null): CoverageMap
    {
        $filter ??= PathFilter::all();
        $files = [];

        foreach ($this->lines as $path => $lines) {
            if ($path === '' || !$filter->accepts($path)) {
                continue;
            }

            $covered = [];
            $uncovered = [];

            foreach ($lines as $line => $status) {
                if ($line < 1) {
                    continue;
                }

                if ($status >= 1) {
                    $covered[] = $line;
                } elseif ($status === -1) {
                    $uncovered[] = $line;
                }
            }

            $functions = $this->functions[$path] ?? [];

            if ($covered === [] && $uncovered === [] && $functions === []) {
                continue;
            }

            $files[] = new FileCoverage($path, $covered, $uncovered, $functions);
        }

        return new CoverageMap($files, $this->branchCoverage);
    }

    /** @return list<FunctionCoverage> */
    private function normalizeFunctions(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $functions = [];

        foreach ($raw as $name => $data) {
            if (!\is_string($name) || $name === '' || !\is_array($data)) {
                continue;
            }

            if (!\str_starts_with($name, '{')) {
                $canonical = \preg_replace('/\{.*}\z/', '', $name);
                $name = \is_string($canonical) && $canonical !== '' ? $canonical : $name;
            }

            $branches = [];
            $rawBranches = $data['branches'] ?? null;
            $branchesByOpcode = [];

            if (\is_array($rawBranches)) {
                foreach ($rawBranches as $rawBranch) {
                    if (!\is_array($rawBranch)) {
                        continue;
                    }

                    $id = $rawBranch['op_start'] ?? null;
                    $endOpcode = $rawBranch['op_end'] ?? null;
                    $startLine = $rawBranch['line_start'] ?? null;
                    $endLine = $rawBranch['line_end'] ?? null;
                    $hit = $rawBranch['hit'] ?? null;

                    if (!\is_int($id) || $id < 0 || !\is_int($endOpcode) || $endOpcode < 0
                        || !\is_int($startLine) || $startLine < 1 || !\is_int($endLine) || $endLine < 1
                        || !\is_int($hit)
                    ) {
                        continue;
                    }

                    $branchesByOpcode[$id] = $rawBranch;
                }
            }

            \ksort($branchesByOpcode);
            $branchIds = [];

            foreach (\array_keys($branchesByOpcode) as $branchId => $opcode) {
                $branchIds[$opcode] = $branchId;
            }

            foreach ($branchesByOpcode as $opcode => $rawBranch) {
                $startLine = $rawBranch['line_start'] ?? null;
                $endLine = $rawBranch['line_end'] ?? null;
                $hit = $rawBranch['hit'] ?? null;

                if (!\is_int($startLine) || !\is_int($endLine) || !\is_int($hit)) {
                    continue;
                }

                $out = $rawBranch['out'] ?? [];
                $outHit = $rawBranch['out_hit'] ?? [];
                $exits = [];

                if (\is_array($out) && \is_array($outHit)) {
                    foreach ($out as $exitId => $target) {
                        $exitId = \is_int($exitId) ? $exitId : (\ctype_digit($exitId) ? (int) $exitId : -1);
                        $exitHit = $outHit[$exitId] ?? null;

                        if ($exitId >= 0 && \is_int($target) && $target >= 0 && \is_int($exitHit)) {
                            $exits[] = new BranchExitCoverage($exitId, $exitHit >= 1);
                        }
                    }
                }

                $branches[] = new BranchCoverage(
                    $branchIds[$opcode],
                    \min($startLine, $endLine),
                    \max($startLine, $endLine),
                    $hit >= 1,
                    $exits,
                );
            }

            $paths = [];
            $rawPaths = $data['paths'] ?? null;

            if (\is_array($rawPaths)) {
                foreach ($rawPaths as $rawPath) {
                    if (!\is_array($rawPath) || !\is_array($rawPath['path'] ?? null) || !\array_is_list($rawPath['path']) || $rawPath['path'] === [] || !\is_int($rawPath['hit'] ?? null)) {
                        continue;
                    }

                    $path = [];

                    foreach ($rawPath['path'] as $branch) {
                        if (!\is_int($branch) || !isset($branchIds[$branch])) {
                            $path = [];
                            break;
                        }

                        $path[] = $branchIds[$branch];
                    }

                    if ($path !== []) {
                        $paths[] = new PathCoverage($path, $rawPath['hit'] >= 1);
                    }
                }
            }

            if ($branches !== [] || $paths !== []) {
                $functions[] = new FunctionCoverage($name, $branches, $paths);
            }
        }

        return $functions;
    }
}
