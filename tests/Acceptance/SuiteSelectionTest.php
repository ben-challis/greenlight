<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SuiteSelectionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function suiteNameSelectionRunsOnlyTheSelectedSuite(): void
    {
        $project = $this->writeProject('suite-name-run');
        $result = GreenlightCli::run($project->directory, ['run', '--suite=unit', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because('a suite name MUST exclude failing base paths and other suites from a run')
            ->toBe(0);
        Expect::that($result->output())
            ->toContain('1 test, 1 passed');
    }

    #[Test]
    public function repeatedNameAndTagSelectorsFormTheListedAndShardedUnion(): void
    {
        $project = $this->writeProject('suite-selector-union');
        $flags = ['--suite=unit', '--suite-tag=io'];
        $full = GreenlightCli::run($project->directory, ['list-tests', ...$flags])->stdoutLines();
        $first = GreenlightCli::run($project->directory, ['list-tests', ...$flags, '--shard=1/2'])->stdoutLines();
        $second = GreenlightCli::run($project->directory, ['list-tests', ...$flags, '--shard=2/2'])->stdoutLines();

        Expect::that($full)
            ->because('suite names and tags MUST select one union before sharding')
            ->toContain('SelectableSuites\\UnitTest::passes')
            ->toContain('SelectableSuites\\IntegrationTest::fails')
            ->not()
            ->toContain('SelectableSuites\\BaseTest::fails');

        $union = [...$this->testIds($first), ...$this->testIds($second)];
        \sort($union);
        $expected = $this->testIds($full);
        \sort($expected);

        Expect::that($union)
            ->because('selected suites MUST divide into disjoint shards after suite selection')
            ->toBe($expected);
    }

    #[Test]
    public function listGroupsAndDryRunUseTheSuiteSelection(): void
    {
        $project = $this->writeProject('suite-list-and-plan');
        $groups = GreenlightCli::run($project->directory, ['run', '--list-groups', '--suite=unit']);
        $plan = GreenlightCli::run($project->directory, ['run', '--dry-run', '--suite-tag=io']);

        Expect::that($groups->output())
            ->because('group listing MUST discover only the selected suites')
            ->toContain('unit (1 tests)')
            ->not()
            ->toContain('base')
            ->not()
            ->toContain('integration');
        Expect::that($plan->output())
            ->because('dry-run output MUST show the effective suite selection')
            ->toContain('test paths: (excluded by suite selection)')
            ->toContain('suite names: (none)')
            ->toContain('suite tags: io')
            ->toContain('suite integration: tests/Integration [tags: io]')
            ->toContain('coverage include paths: src')
            ->not()
            ->toContain('suite unit:');
    }

    #[Test]
    public function unknownSelectorsFailBeforeDiscoveryWithListingGuidance(): void
    {
        $project = $this->writeProject('suite-unknown');
        $name = GreenlightCli::run($project->directory, ['run', '--suite=missing', '--no-ansi']);
        $tag = GreenlightCli::run($project->directory, ['list-tests', '--suite-tag=missing', '--no-ansi']);

        Expect::that($name->exitCode)
            ->because('an unknown suite name MUST be a usage error')
            ->toBe(64);
        Expect::that($name->output())
            ->toContain('Unknown suite "missing". Use --list-suites to list configured suites.');
        Expect::that($tag->exitCode)
            ->because('an unknown suite tag MUST be a usage error')
            ->toBe(64);
        Expect::that($tag->output())
            ->toContain('Unknown suite tag "missing". Use --list-suites to list configured suite tags.');
    }

    #[Test]
    public function listSuitesKeepsAllConfiguredNamesAndTagsVisible(): void
    {
        $project = $this->writeProject('suite-list-suites');
        $result = GreenlightCli::run($project->directory, ['run', '--list-suites', '--suite=unit']);

        Expect::that($result->exitCode)
            ->because('--list-suites MUST validate selectors and list the complete configured catalog')
            ->toBe(0);
        Expect::that($result->output())
            ->toContain('unit: tests/Unit [tags: fast]')
            ->toContain('integration: tests/Integration [tags: io]')
            ->toContain('2 suites');
    }

    private function writeProject(string $name): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('tests/BaseTest.php', $this->testSource('BaseTest', 'base', fails: true));
        $project->writeFile('tests/Unit/UnitTest.php', $this->testSource('UnitTest', 'unit'));
        $project->writeFile('tests/Integration/IntegrationTest.php', $this->testSource('IntegrationTest', 'integration', fails: true));
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\SuiteBuilder;

            require_once __DIR__ . '/tests/BaseTest.php';
            require_once __DIR__ . '/tests/Unit/UnitTest.php';
            require_once __DIR__ . '/tests/Integration/IntegrationTest.php';

            return GreenlightConfig::create()
                ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit')->tag('fast'))
                ->suite('integration', static fn(SuiteBuilder $suite) => $suite->in('tests/Integration')->tag('io'))
                ->coverage(static fn(CoverageBuilder $coverage) => $coverage->include('src'))
                ->workers(1);

            PHP);

        return $project;
    }

    private function testSource(string $class, string $group, bool $fails = false): string
    {
        $assertion = $fails ? 'Expect::that(false)->toBeTrue();' : 'Expect::that(true)->toBeTrue();';

        return \sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace SelectableSuites;

                use Greenlight\Attribute\Group;
                use Greenlight\Attribute\Test;
                use Greenlight\Expect\Expect;

                final class %s
                {
                    #[Test]
                    #[Group('%s')]
                    public function %s(): void
                    {
                        %s
                    }
                }

                PHP,
            $class,
            $group,
            $fails ? 'fails' : 'passes',
            $assertion,
        );
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function testIds(array $lines): array
    {
        return \array_values(\array_filter(
            $lines,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }
}
