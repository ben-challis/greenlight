<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --exclude-class and --exclude-path through the real CLI.
 *
 * --exclude-class matches by substring or wildcard against the class name;
 * --exclude-path matches by prefix against the discovered file's absolute
 * path. Relative prefixes are resolved against the working directory (and
 * canonicalized when they exist on disk) before matching.
 *
 * An --exclude-path prefix that matches no discovered test file warns on
 * stderr, for listings and runs alike, so a typo'd or stale prefix is
 * visible instead of silently excluding nothing.
 */
final class ExcludeSelectionTest
{
    #[Test]
    public function excludeClassRemovesOnlyTheMatchingClass(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $project->runLines('list-tests', '--exclude-class=BExcludeProbeTest');

            Expect::that($exit)->toBe(0);
            $this->assertIds($lines, present: [
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ], absent: [
                'ExcludeProbe\BExcludeProbeTest::one',
            ]);

            [$exit, $output] = $project->run('run', '--reporter=plain', '--exclude-class=BExcludeProbeTest');
            Expect::that($exit)->toBe(0)->and($output)->toContain('2 tests, 2 passed');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludeClassAcceptsAWildcard(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $project->runLines('list-tests', '--exclude-class=*BExcludeProbeTest');

            Expect::that($exit)->toBe(0);
            $this->assertIds($lines, present: [
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ], absent: [
                'ExcludeProbe\BExcludeProbeTest::one',
            ]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludePathRemovesTestsUnderThatPrefix(): void
    {
        $project = $this->writeProject();

        try {
            // realpath(), not project->path(): discovery reports the
            // symlink-resolved absolute path (macOS temp dirs alias
            // /var/folders/... to /private/var/folders/...), and the
            // prefix match is exact.
            $excludedFile = (string) \realpath($project->path('tests/CExcludeProbeTest.php'));
            [$exit, $lines] = $project->runLines('list-tests', '--exclude-path=' . $excludedFile);

            Expect::that($exit)->toBe(0);
            $this->assertIds($lines, present: [
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
            ], absent: [
                'ExcludeProbe\CExcludeProbeTest::one',
            ]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludePathResolvesARelativePrefixAgainstTheWorkingDirectory(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $project->runLines('list-tests', '--exclude-path=tests/CExcludeProbeTest.php');

            Expect::that($exit)->toBe(0);
            $this->assertIds($lines, present: [
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
            ], absent: [
                'ExcludeProbe\CExcludeProbeTest::one',
            ]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludePathResolvesARelativeDirectoryPrefix(): void
    {
        $project = $this->writeProject();
        $project->write('tests/nested/DExcludeProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ExcludeProbe;

            use Greenlight\Attribute\Test;

            final class DExcludeProbeTest
            {
                #[Test]
                public function one(): void {}
            }
            PHP);
        $project->writeConfig([
            'tests/AExcludeProbeTest.php',
            'tests/BExcludeProbeTest.php',
            'tests/CExcludeProbeTest.php',
            'tests/nested/DExcludeProbeTest.php',
        ]);

        try {
            [$exit, $lines] = $project->runLines('list-tests', '--exclude-path=tests/nested');

            Expect::that($exit)->toBe(0);
            $this->assertIds($lines, present: [
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ], absent: [
                'ExcludeProbe\DExcludeProbeTest::one',
            ]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludePathWarnsWhenThePrefixMatchesNoTestFile(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $project->run('list-tests', '--exclude-path=tests/MissingProbeTest.php');

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('matched no discovered test file')
                ->and($output)->toContain('MissingProbeTest.php')
                ->and($output)->toContain('3 tests');

            [$exit, $output] = $project->run('run', '--reporter=plain', '--exclude-path=tests/MissingProbeTest.php');

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('matched no discovered test file')
                ->and($output)->toContain('3 tests, 3 passed');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function excludePathDoesNotWarnWhenThePrefixMatchesATestFile(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $project->run('list-tests', '--exclude-path=tests/CExcludeProbeTest.php');

            Expect::that($exit)->toBe(0)
                ->and($output)->not()->toContain('matched no discovered test file');
        } finally {
            $project->remove();
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $present
     * @param list<string> $absent
     */
    private function assertIds(array $lines, array $present, array $absent): void
    {
        foreach ($present as $id) {
            Expect::that($id)->toBeIn($lines);
        }

        foreach ($absent as $id) {
            Expect::that($id)->not()->toBeIn($lines);
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('exclude-selection');

        foreach (['A', 'B', 'C'] as $letter) {
            $project->write(\sprintf('tests/%sExcludeProbeTest.php', $letter), <<<PHP
                <?php

                declare(strict_types=1);

                namespace ExcludeProbe;

                use Greenlight\Attribute\Test;

                final class {$letter}ExcludeProbeTest
                {
                    #[Test]
                    public function one(): void {}
                }
                PHP);
        }

        $project->writeConfig([
            'tests/AExcludeProbeTest.php',
            'tests/BExcludeProbeTest.php',
            'tests/CExcludeProbeTest.php',
        ]);

        return $project;
    }
}
