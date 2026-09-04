<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

#[SkipUnless(ExtensionLoaded::class, 'pcov')]
final readonly class DisabledPcovTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    public function aDisabledPcovExtensionCannotSatisfyRequiredCoverage(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->directory, 'disabled-pcov');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/PassingTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                    ->driver('pcov')
                    ->requireDriver(true));
            PHP);

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain', '--no-ansi'],
            phpArguments: ['-d', 'pcov.enabled=0'],
        );

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stdout)->toContain('1 test, 1 passed');
        Expect::that($result->stderr)->toContain('Coverage is required, but no worker collected it.');
        Expect::that($result->stdout)->not()->toContain('Coverage: 100.00%');
    }
}
