<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

/**
 * Drives bin/greenlight against a fixture project whose only unexecuted
 * lines sit inside coverage ignore markers, so the run reports full
 * coverage exactly when the markers are honoured.
 */
final class CoverageIgnoreRunTest
{
    private const string CONFIG_DIR = 'tests/Fixture/CoverageIgnoreConfig';

    #[Test]
    public function ignoredLinesAreExcludedFromTotalsAndExports(): void
    {
        $outDir = $this->outDir();
        AcceptanceProject::removeTree($outDir);

        try {
            $root = \dirname(__DIR__, 2);
            $result = GreenlightCli::run(
                $root . '/' . self::CONFIG_DIR,
                ['run', '--reporter=plain'],
                ['XDEBUG_MODE' => 'coverage'],
            );

            Expect::that($result->exitCode)->toBe(0)
                ->and($result->output())->toContain('Coverage: 100.00%');

            $json = \file_get_contents($outDir . '/coverage.json');

            if ($json === false) {
                throw new \RuntimeException('The JSON export was not written.');
            }

            /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            $gadget = null;

            foreach ($decoded['files'] as $file => $lines) {
                if (\str_ends_with($file, 'CoverageIgnoreLib/Gadget.php')) {
                    $gadget = $lines;
                }
            }

            Expect::that($gadget)->not()->toBeNull()
                ->and($gadget['uncovered'] ?? null)->toBe([])
                ->and($gadget['covered'] ?? [])->not()->toHaveCount(0);
        } finally {
            AcceptanceProject::removeTree($outDir);
        }
    }

    private function outDir(): string
    {
        return \dirname(__DIR__, 2) . '/' . self::CONFIG_DIR . '/coverage-out';
    }
}
