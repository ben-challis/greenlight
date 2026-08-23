<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedCallShapeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedCallShapesUntouched(): void
    {
        $cases = [
            'PHPUnit static call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        TestCase::assertTrue(true);
                    }
                }

                PHP_WRAP,
            'PHPUnit function call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        \PHPUnit\Framework\assertTrue(true);
                    }
                }

                PHP_WRAP,
            'parent assertion call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        parent::assertTrue(true);
                    }
                }

                PHP_WRAP,
            'dynamic method call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $method = 'checkValue';
                        $this->{$method}(1);
                    }

                    private function checkValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'nullsafe method call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $this?->checkValue(1);
                    }

                    private function checkValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'first-class callable' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $failure = $this->fail(...);
                    }
                }

                PHP_WRAP,
            'unsupported Assert call' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Assert;
                use PHPUnit\Framework\Constraint\IsTrue;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        Assert::assertThat(true, new IsTrue());
                    }
                }

                PHP_WRAP,
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-call-shapes');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported call shape: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported call shape: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
