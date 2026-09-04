<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
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
    #[DataSet('requirements')]
    public function aDisabledPcovExtensionIsTreatedAsUnavailable(bool $required): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->directory, 'disabled-pcov');
        $project->writeFile('greenlight.php', \sprintf(<<<'PHP'
            <?php

            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/PassingTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                    ->driver('pcov')
                    ->requireDriver(%s));
            PHP, $required ? 'true' : 'false'));

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain', '--no-ansi'],
            phpArguments: ['-d', 'pcov.enabled=0'],
        );

        $evidence = \sprintf('stdout: %s\nstderr: %s', \substr($result->stdout, 0, 2_000), \substr($result->stderr, 0, 2_000));

        Expect::that($result->exitCode)->because($evidence)->toBe($required ? 1 : 0);
        Expect::that($result->stdout)->because($evidence)->toContain('PASS ');
        Expect::that($result->output())->not()->toContain('NativePcovRuntime::collect()');

        if ($required) {
            Expect::that($result->stderr)->because($evidence)->toContain('Coverage is required, but no worker collected it.');
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function requirements(): iterable
    {
        yield 'optional coverage' => [false];
        yield 'required coverage' => [true];
    }
}
