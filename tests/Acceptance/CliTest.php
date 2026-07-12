<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Runs bin/greenlight as a subprocess and asserts on observable behaviour
 * only: exit codes and output lines.
 */
final class CliTest
{
    #[Test]
    public function printsTheResolvedPlanForAFixtureConfig(): void
    {
        [$exit, $output] = $this->runCli(['--dry-run', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php']);

        Expect::that($exit)->toBe(0);
        $this->assertContainsLine($output, '  test paths: tests/Unit, tests/Acceptance');
        $this->assertContainsLine($output, '  suite unit: tests/Unit');
        $this->assertContainsLine($output, '  suite integration: tests/Integration [tags: io]');
        $this->assertContainsLine($output, '  workers: 4');
        $this->assertContainsLine($output, '  recycle: after 100 tests or above 128M memory');
        $this->assertContainsLine($output, '  stop after: 1 failure');
        $this->assertContainsLine($output, '  order: random (seed 4242)');
        $this->assertContainsLine($output, '  groups: (all)');
    }

    #[Test]
    public function commandLineFlagsOverrideTheConfigFile(): void
    {
        [$exit, $output] = $this->runCli([
            '--dry-run',
            '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php',
            '--workers=2',
            '--bail=7',
            '--seed=9',
            '--group=slow',
        ]);

        Expect::that($exit)->toBe(0);
        $this->assertContainsLine($output, '  workers: 2');
        $this->assertContainsLine($output, '  stop after: 7 failures');
        $this->assertContainsLine($output, '  order: random (seed 9)');
        $this->assertContainsLine($output, '  groups: slow');
    }

    #[Test]
    public function helpAndVersionExitZero(): void
    {
        [$helpExit, $helpOutput] = $this->runCli(['--help']);
        Expect::that($helpExit)->toBe(0);
        Expect::that(\implode("\n", $helpOutput))->toContain('Usage:');

        [$versionExit, $versionOutput] = $this->runCli(['--version']);
        Expect::that($versionExit)->toBe(0);
        $this->assertContainsLine($versionOutput, 'Greenlight dev-main');
    }

    #[Test]
    public function runExecutesAPassingSuiteAndExitsZero(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('cli');

        try {
            [$exit, $output] = $project->runLines('run');

            Expect::that($exit)->toBe(0);
            Expect::that(\implode("\n", $output))->toContain('7 tests, 7 passed');
            Expect::that(\implode("\n", $output))->not()->toContain('alpha:one');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function noAnsiAndVerboseAreAcceptedAndOutputStaysEscapeFree(): void
    {
        // The subprocess pipes stdout, so detection already lands on plain
        // output with or without the flag; this pins flag parsing and the
        // escape-free contract, while the TTY behaviour matrix lives in
        // TerminalCapabilitiesTest and TtyReporterTest.
        $project = AcceptanceProject::copyOfListTestsConfig('cli');

        try {
            [$exit, $output] = $project->runLines('run', '--no-ansi', '--verbose');

            Expect::that($exit)->toBe(0);
            Expect::that(\implode("\n", $output))->not()->toContain("\x1b[");
            Expect::that(\implode("\n", $output))->toContain('7 tests, 7 passed');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function runExecutesAFailingSuiteAndExitsOne(): void
    {
        [$exit, $output] = $this->runCli(['run'], 'tests/Fixture/RunFailingConfig');

        Expect::that($exit)->toBe(1);
        Expect::that(\implode("\n", $output))->toContain('intentional boom');
    }

    #[Test]
    public function runWithNoTestsExitsOne(): void
    {
        [$exit, $output] = $this->runCli(['run'], 'tests/Fixture/RunEmptyConfig');

        Expect::that($exit)->toBe(1);
        Expect::that(\implode("\n", $output))->toContain('No tests found');
    }

    #[Test]
    public function listTestsPrintsDiscoveredTestIds(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('cli');

        try {
            [$exit, $output] = $project->runLines('list-tests');

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function listTestsHonoursGroupFilters(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('cli');

        try {
            [$exit, $output] = $project->runLines('list-tests', '--group=slow');

            Expect::that($exit)->toBe(0);
            $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
            Expect::that(
                !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one', $output, true),
            )->toBeTrue();
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function missingConfigFileFailsWithAnActionableMessage(): void
    {
        [$exit, $output] = $this->runCli([], 'tests/Fixture/ConfigFiles/Empty');

        Expect::that($exit)->toBe(1);
        Expect::that(\implode("\n", $output))->toContain('greenlight: No greenlight.php found in');
    }

    #[Test]
    public function unknownOptionsAreUsageErrors(): void
    {
        [$exit, $output] = $this->runCli(['--frobnicate']);

        Expect::that($exit)->toBe(64);
        Expect::that(\implode("\n", $output))->toContain('greenlight: Unknown option "--frobnicate"');
        Expect::that(\implode("\n", $output))->not()->toContain("\x1b[");
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, list<string>}
     */
    private function runCli(array $arguments, string $relativeCwd = ''): array
    {
        $root = \dirname(__DIR__, 2);
        $cwd = $relativeCwd === '' ? $root : $root . '/' . $relativeCwd;

        return AcceptanceProject::runLinesIn($cwd, $arguments);
    }

    /**
     * @param list<string> $output
     */
    private function assertContainsLine(array $output, string $expected): void
    {
        if (!\in_array($expected, $output, true)) {
            throw new \RuntimeException(\sprintf(
                "Expected output to contain the line '%s'. Got:\n%s",
                $expected,
                \implode("\n", $output),
            ));
        }
    }
}
