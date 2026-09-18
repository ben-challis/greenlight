<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorDefaultSkipReasonTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function skipWithoutAReasonGetsANonEmptyDefault(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testSkips(): void
                {
                    $this->markTestSkipped();
                }
            }

            PHP_WRAP,
            name: 'default-skip-reason',
        );

        expect($probe->changed)
            ->because('a PHPUnit skip without a reason MUST be convertible')
            ->toBeTrue();
        expect($probe->code)
            ->because('Greenlight skips MUST have a non-empty reason')
            ->toContain("throw new \Greenlight\Test\SkipTest('Skipped.');");
    }
}
