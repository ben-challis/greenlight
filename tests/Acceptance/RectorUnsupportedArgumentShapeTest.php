<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedArgumentShapeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedArgumentShapesUntouched(): void
    {
        $cases = [
            'surplus TestWith argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\TestWith;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[TestWith([1], 'one', 'extra')]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'unpacked TestWith argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\TestWith;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[TestWith(...[[1]])]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'surplus skip argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $this->markTestSkipped('Not supported.', 'extra');
                    }
                }

                PHP_WRAP,
            'surplus exception expectation argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testFailure(): void
                    {
                        $this->expectException(\RuntimeException::class, 'surplus');
                        throw new \RuntimeException('failure');
                    }
                }

                PHP_WRAP,
            'named assertion argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $this->assertSame(expected: 'value', actual: 'value');
                    }
                }

                PHP_WRAP,
            'unpacked assertion argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $arguments = [true];

                        $this->assertTrue(...$arguments);
                    }
                }

                PHP_WRAP,
            'invalid TestWith label' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\TestWith;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[TestWith([1], label: 'one')]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'surplus no-expectations argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $this->expectNotToPerformAssertions('extra');
                    }
                }

                PHP_WRAP,
            'surplus fail argument' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testValue(): void
                    {
                        $this->fail('Not supported.', 'extra');
                    }
                }

                PHP_WRAP,
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-argument-shapes');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported argument shape: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported argument shape: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
