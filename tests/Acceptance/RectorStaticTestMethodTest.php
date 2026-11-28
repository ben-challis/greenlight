<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorStaticTestMethodTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function keepsClassesWithAStaticTestMethodOnPhpUnit(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testValue(): void
                {
                    $this->assertSame(1, 1);
                }

                public static function testStatic(): void
                {
                    self::assertSame(1, 1);
                }
            }

            PHP_WRAP, name: 'static-test-method');

        expect($probe->changed)->toBeFalse();
    }

    #[Test]
    public function preservesCaseSensitiveTestDiscovery(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\Test;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function TestHelper(): int
                {
                    return 1;
                }

                public static function TESTStaticHelper(): int
                {
                    return 2;
                }

                public function testConvention(): void
                {
                    $this->assertSame(1, $this->TestHelper());
                }

                #[Test]
                public function TestExplicit(): void
                {
                    $this->assertSame(2, self::TESTStaticHelper());
                }
            }

            PHP_WRAP, name: 'test-method-name-case');

        expect($probe->changed)->toBeTrue();
        $run = $probe->runConvertedTests();
        expect($run->exitCode)->toBe(0);
        expect($run->stdout)->toContain('2 tests, 2 passed');
    }

    #[Test]
    public function keepsClassesWithAStaticTestThatIsAlsoADataProviderOnPhpUnit(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\DataProvider;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[DataProvider('testCases')]
                public function testValue(int $value): void
                {
                    $this->assertSame(1, $value);
                }

                public static function testCases(): iterable
                {
                    yield [1];
                }
            }

            PHP_WRAP, name: 'static-test-named-provider');

        expect($probe->changed)->toBeFalse();
    }
}
