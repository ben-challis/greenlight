<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorOperatingSystemRequirementTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function operatingSystemRequirementsKeepTheirRuntimeBehavior(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[RequiresOperatingSystemFamily(\PHP_OS_FAMILY)]
                public function testCurrentOperatingSystem(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
            name: 'operating-system-requirement',
        );

        Expect::that($probe->changed)
            ->because('the operating-system requirement MUST be convertible')
            ->toBeTrue();
        Expect::that($probe->code)
            ->because('the converted condition MUST keep the requested operating-system family')
            ->toContain(
                '#[\Greenlight\Attribute\SkipUnless('
                    . '\Greenlight\Condition\OperatingSystemFamily::class, \PHP_OS_FAMILY)]',
            );

        \file_put_contents($probe->directory . '/greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/ProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);

            PHP);

        $run = GreenlightCli::run($probe->directory, ['run', '--no-ansi']);

        Expect::that($run->exitCode)
            ->because('the converted operating-system condition MUST permit the matching family')
            ->toBe(0);
        Expect::that($run->stdout)
            ->toContain('1 test, 1 passed');
    }
}
