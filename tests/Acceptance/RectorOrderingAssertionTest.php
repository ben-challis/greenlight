<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorOrderingAssertionTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function keepsOrderingAssertionsWithNonNumericOperandsOnPhpUnit(): void
    {
        $cases = [
            'string operands' => $this->source(<<<'PHP'
                $this->assertGreaterThan('2026-01-01', '2026-02-01');
                PHP),
            'string actual value' => $this->source(<<<'PHP'
                $this->assertLessThan(3, '2');
                PHP),
            'string bound' => $this->source(<<<'PHP'
                $this->assertGreaterThanOrEqual('2', 2);
                PHP),
        ];

        $probes = RectorProbe::convertBatch($this->workspace, $cases, name: 'nonnumeric-ordering');

        foreach ($probes as $caseName => $probe) {
            expect($probe->code)->because('Ordering assertion case: ' . $caseName . '.')->toBe($cases[$caseName]);
        }
    }

    #[Test]
    public function convertsOrderingAssertionsForIntegersAndFloats(): void
    {
        $probe = RectorProbe::convert(
            $this->workspace,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\TestWith;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[TestWith([2])]
                #[TestWith([2.5])]
                public function testOrder(int|float $number): void
                {
                    $this->assertGreaterThan(1, $number);
                    $this->assertGreaterThanOrEqual(2.0, $number);
                    $this->assertLessThan(3.0, $number);
                    $this->assertLessThanOrEqual(2.5, $number);
                }
            }

            PHP_WRAP,
            name: 'numeric-ordering',
        );

        expect($probe->changed)->toBeTrue();
        $run = $probe->runConvertedTests();
        expect($run->exitCode)->toBe(0);
        expect($run->stdout)->toContain('2 tests, 2 passed');
    }

    private function source(string $assertion): string
    {
        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testOrder(): void
                {
                    {$assertion}
                }
            }

            PHP_WRAP;
    }
}
