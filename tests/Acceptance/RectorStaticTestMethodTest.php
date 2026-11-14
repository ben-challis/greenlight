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
    public function convertsAStaticTestNamedDataProvider(): void
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

        expect($probe->changed)->toBeTrue();
        expect($probe->code)->toContain("#[\\Greenlight\\Attribute\\DataSet('testCases')]");
    }
}
