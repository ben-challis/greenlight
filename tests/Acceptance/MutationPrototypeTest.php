<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

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
        $project = AcceptanceProject::copyOf($root . '/tests/Fixture/MutationPrototype/project', 'mutation');
        $subject = $project->path('src/Temperature.php');
        $pristine = \file_get_contents($subject);
        \assert(\is_string($pristine));


        try {
            // The loop is meaningless unless the unmutated suite passes.
            [$exit] = $this->runSuite($project);
            Expect::that($exit)->toBe(0);

            // Mutant one: relax the freezing boundary. zeroIsFreezing covers
            // exactly this boundary, so the mutant must die and the report
            // must attribute the kill to that test.
            \file_put_contents($subject, \str_replace('$celsius <= 0.0', '$celsius < 0.0', $pristine));
            [$exit, $output] = $this->runSuite($project);
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('FAIL MutationPrototype\Tests\TemperatureTest::zeroIsFreezing');

            // Mutant two: change describe()'s wording. Nothing tests
            // describe(), so the mutant survives; that survival is the
            // signal a real mutation tool would report.
            \file_put_contents($subject, \str_replace("'freezing'", "'cold'", $pristine));
            [$exit] = $this->runSuite($project);
            Expect::that($exit)->toBe(0);
        } finally {
            \file_put_contents($subject, $pristine);
            $project->remove();
        }
    }

    /**
     * @return array{int, string}
     */
    private function runSuite(AcceptanceProject $project): array
    {
        return $project->run('run', '--reporter=plain');
    }
}
