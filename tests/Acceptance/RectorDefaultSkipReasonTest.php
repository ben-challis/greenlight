<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDefaultSkipReasonTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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

        Expect::that($probe->changed)
            ->because('a PHPUnit skip without a reason MUST be convertible')
            ->toBeTrue()
            ->and($probe->code)
            ->because('Greenlight skips MUST have a non-empty reason')
            ->toContain("throw new \Greenlight\Core\Test\SkipTest('Skipped.');");
    }
}
