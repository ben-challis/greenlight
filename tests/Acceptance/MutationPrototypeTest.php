<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * The mutation-testing half of the extensibility proof.
 *
 * It is a prototype mutation loop built entirely on public surfaces (the CLI,
 * its exit codes, and the plain reporter's failure lines), with no core
 * patching.
 *
 * Each mutant is a source edit in a throwaway copy of a small project; a
 * mutant is killed when the suite fails against it and the failing test is
 * attributable from the report.
 */
final class MutationPrototypeTest
{
    #[Test]
    public function mutantsAreKilledByCoveringTestsAndSurviveWithoutCoverage(): void
    {
        $root = \dirname(__DIR__, 2);
        $project = $this->copyProject($root . '/tests/Fixture/MutationPrototype/project');
        $subject = $project . '/src/Temperature.php';
        $pristine = \file_get_contents($subject);
        \assert(\is_string($pristine));

        $expect = new Expect();

        try {
            // The loop is meaningless unless the unmutated suite passes.
            [$exit] = $this->runSuite($root, $project);
            $expect->that($exit)->toBe(0);

            // Mutant one: relax the freezing boundary. zeroIsFreezing covers
            // exactly this boundary, so the mutant must die and the report
            // must attribute the kill to that test.
            \file_put_contents($subject, \str_replace('$celsius <= 0.0', '$celsius < 0.0', $pristine));
            [$exit, $output] = $this->runSuite($root, $project);
            $expect->that($exit)->toBe(1)
                ->and($output)->toContain('FAIL MutationPrototype\Tests\TemperatureTest::zeroIsFreezing');

            // Mutant two: change describe()'s wording. Nothing tests
            // describe(), so the mutant survives; that survival is the
            // signal a real mutation tool would report.
            \file_put_contents($subject, \str_replace("'freezing'", "'cold'", $pristine));
            [$exit] = $this->runSuite($root, $project);
            $expect->that($exit)->toBe(0);
        } finally {
            \file_put_contents($subject, $pristine);
            $this->removeTree($project);
        }
    }

    /**
     * @return array{int, string}
     */
    private function runSuite(string $root, string $project): array
    {
        $command = \sprintf(
            'cd %s && %s %s run --reporter=plain 2>&1',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/bin/greenlight'),
        );

        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    private function copyProject(string $source): string
    {
        $target = \sys_get_temp_dir() . '/greenlight-mutation-' . \bin2hex(\random_bytes(6));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        \mkdir($target, 0o777, true);

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $destination = $target . '/' . \substr($item->getPathname(), \strlen($source) + 1);

            if ($item->isDir()) {
                \mkdir($destination, 0o777, true);
            } else {
                \copy($item->getPathname(), $destination);
            }
        }

        return $target;
    }

    private function removeTree(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }

        @\rmdir($directory);
    }
}
