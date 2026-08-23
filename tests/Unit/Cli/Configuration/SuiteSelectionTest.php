<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\SuiteSelectionResolver;
use Greenlight\Cli\Input\CliError;
use Greenlight\Config\DiscoveryConfiguration;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Config\SuiteSelection;
use Greenlight\Expect\Expect;

final readonly class SuiteSelectionTest
{
    #[Test]
    public function absentSelectorsIncludeBasePathsAndAllSuites(): void
    {
        $configuration = $this->configuration();
        $selection = SuiteSelectionResolver::resolve($configuration, [], []);

        Expect::that($selection->paths($configuration))
            ->because('the compatibility selection MUST include base paths and all suite paths')
            ->toBe(['tests', 'tests/Unit', 'tests/Integration', 'tests/Api']);
        Expect::that($selection->stateIdentity())
            ->because('the compatibility selection MUST keep the existing run-state identity')
            ->toBeNull();
    }

    #[Test]
    public function nameAndTagSelectorsFormAUnionWithoutBasePaths(): void
    {
        $configuration = $this->configuration();
        $selection = SuiteSelectionResolver::resolve($configuration, ['unit'], ['io']);

        Expect::that(\array_map(static fn(SuiteConfiguration $suite): string => $suite->name, $selection->suites))
            ->because('suite names and tags MUST form one union in configuration order')
            ->toBe(['unit', 'integration', 'api']);
        Expect::that($selection->paths($configuration))
            ->because('an explicit suite selection MUST exclude unrelated base paths')
            ->toBe(['tests/Unit', 'tests/Integration', 'tests/Api']);
        Expect::that($selection->stateIdentity())
            ->because('an explicit suite selection MUST have a separate run-state identity')
            ->toStartWith('suites-');
    }

    #[Test]
    public function equivalentSelectorsHaveTheSameCanonicalIdentity(): void
    {
        $configuration = $this->configuration();
        $byNames = SuiteSelectionResolver::resolve($configuration, ['api', 'integration'], []);
        $byTag = SuiteSelectionResolver::resolve($configuration, [], ['io']);

        Expect::that($byNames->stateIdentity())
            ->because('equivalent suite unions MUST share run state and timing data')
            ->toBe($byTag->stateIdentity());
    }

    #[Test]
    public function unknownNamesAndTagsGiveActionableUsageErrors(): void
    {
        $configuration = $this->configuration();

        Expect::that(static fn(): SuiteSelection => SuiteSelectionResolver::resolve($configuration, ['missing'], []))
            ->because('an unknown suite name MUST identify the selector and the listing command')
            ->toThrow(CliError::class, message: 'Unknown suite "missing". Use --list-suites to list configured suites.');
        Expect::that(static fn(): SuiteSelection => SuiteSelectionResolver::resolve($configuration, [], ['missing']))
            ->because('an unknown suite tag MUST identify the selector and the listing command')
            ->toThrow(CliError::class, message: 'Unknown suite tag "missing". Use --list-suites to list configured suite tags.');
    }

    private function configuration(): DiscoveryConfiguration
    {
        return new DiscoveryConfiguration(
            ['tests'],
            [
                new SuiteConfiguration('unit', ['tests/Unit'], ['fast']),
                new SuiteConfiguration('integration', ['tests/Integration'], ['io']),
                new SuiteConfiguration('api', ['tests/Api'], ['io']),
            ],
        );
    }
}
