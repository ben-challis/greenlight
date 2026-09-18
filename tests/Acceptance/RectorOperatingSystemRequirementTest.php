<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorOperatingSystemRequirementTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

        expect($probe->changed)
            ->because('the operating-system requirement MUST be convertible')
            ->toBeTrue();
        expect($probe->code)
            ->because('the converted condition MUST keep the requested operating-system family')
            ->toContain(
                '#[\Greenlight\Attribute\SkipUnless('
                    . '\Greenlight\Condition\OperatingSystemFamily::class, \PHP_OS_FAMILY)]',
            );

        $run = $probe->runConvertedTests();

        expect($run->exitCode)
            ->because('the converted operating-system condition MUST permit the matching family')
            ->toBe(0);
        expect($run->stdout)
            ->toContain('1 test, 1 passed');
    }
}
