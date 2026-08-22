<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class RunDiscoveryErrorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function runReportsDiscoveryFailuresCleanly(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'run-discovery-error');
        $fixture = FixturePath::get('DiscoveryProviderMissing');
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                use Greenlight\Config\GreenlightConfig;

                return GreenlightConfig::create()
                    ->paths([%s])
                    ->workers(1);

                PHP,
            \var_export($fixture, true),
        ));

        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('a discovery failure MUST stop the run cleanly')
            ->toBe(1);
        Expect::that($result->stderr)
            ->toContain('MissingProviderTest::needsData() references data-set provider')
            ->toContain('MissingProviderTest::doesNotExist(), but the provider does not exist.');
    }
}
