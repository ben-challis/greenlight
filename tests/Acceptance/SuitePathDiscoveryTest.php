<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SuitePathDiscoveryTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function namedSuitePathsParticipateInTestDiscovery(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'suite-path-discovery');
        $topLevel = \dirname(__DIR__) . '/Fixture/DiscoveryBasic';
        $suite = \dirname(__DIR__) . '/Fixture/Lifecycle/Bail';

        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\SuiteBuilder;

            return GreenlightConfig::create()
                ->paths([%s])
                ->suite('bail', static fn(SuiteBuilder $suite) => $suite->in(%s));

            PHP,
            \var_export($topLevel, true),
            \var_export($suite, true),
        ));

        $result = GreenlightCli::run($project->directory, ['run', '--list-tests']);

        Expect::that($result->exitCode)
            ->because('test discovery MUST include top-level and named-suite paths')
            ->toBe(0)
            ->and($result->output())
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('Greenlight\Tests\Fixture\Lifecycle\Bail\AaTest::fails')
            ->toContain('Greenlight\Tests\Fixture\Lifecycle\Bail\BbTest::wouldAlsoPass');
    }
}
