<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\Check;

/**
 * Runs bin/greenlight as a subprocess and asserts on observable behaviour
 * only: exit codes and output lines.
 */
final class CliTest
{
    #[Test]
    public function printsTheResolvedPlanForAFixtureConfig(): void
    {
        [$exit, $output] = $this->runCli(['--config=tests/Fixture/ConfigFiles/Valid/greenlight.php']);

        Check::same(0, $exit, 'exit code');
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
            '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php',
            '--workers=2',
            '--bail=7',
            '--seed=9',
            '--group=slow',
        ]);

        Check::same(0, $exit, 'exit code');
        $this->assertContainsLine($output, '  workers: 2');
        $this->assertContainsLine($output, '  stop after: 7 failures');
        $this->assertContainsLine($output, '  order: random (seed 9)');
        $this->assertContainsLine($output, '  groups: slow');
    }

    #[Test]
    public function helpAndVersionExitZero(): void
    {
        [$helpExit, $helpOutput] = $this->runCli(['--help']);
        Check::same(0, $helpExit, 'help exit code');
        Check::true(
            \str_contains(\implode("\n", $helpOutput), 'Usage:'),
            'help output to contain a usage section',
        );

        [$versionExit, $versionOutput] = $this->runCli(['--version']);
        Check::same(0, $versionExit, 'version exit code');
        $this->assertContainsLine($versionOutput, 'Greenlight dev-main');
    }

    #[Test]
    public function listTestsPrintsDiscoveredTestIds(): void
    {
        [$exit, $output] = $this->runCli(['list-tests'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'list-tests exit code');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
    }

    #[Test]
    public function listTestsHonoursGroupFilters(): void
    {
        [$exit, $output] = $this->runCli(['list-tests', '--group=slow'], 'tests/Fixture/ListTestsConfig');

        Check::same(0, $exit, 'filtered list-tests exit code');
        $this->assertContainsLine($output, 'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
        Check::true(
            !\in_array('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one', $output, true),
            'ungrouped test to be filtered out',
        );
    }

    #[Test]
    public function missingConfigFileFailsWithAnActionableMessage(): void
    {
        [$exit, $output] = $this->runCli([], 'tests/Fixture/ConfigFiles/Empty');

        Check::same(1, $exit, 'exit code without a config file');
        Check::true(
            \str_contains(\implode("\n", $output), 'No greenlight.php found in'),
            'the error to name the missing file',
        );
    }

    #[Test]
    public function unknownOptionsAreUsageErrors(): void
    {
        [$exit, $output] = $this->runCli(['--frobnicate']);

        Check::same(64, $exit, 'exit code for an unknown option');
        Check::true(
            \str_contains(\implode("\n", $output), "Unknown option '--frobnicate'"),
            'the error to name the unknown option',
        );
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

        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight')];

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        $command = \sprintf('cd %s && %s 2>&1', \escapeshellarg($cwd), \implode(' ', $parts));

        \exec($command, $output, $exit);
        $lines = $output;

        return [$exit, $lines];
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
