<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedDataProviderTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedDataProvidersUntouched(): void
    {
        $cases = [
            'non-static data provider' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\DataProvider;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[DataProvider('values')]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }

                    public function values(): iterable
                    {
                        yield [1];
                    }
                }

                PHP_WRAP,
            'missing data provider' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\DataProvider;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[DataProvider('missing')]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
            'private data provider' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\DataProvider;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[DataProvider('values')]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }

                    private static function values(): iterable
                    {
                        yield [1];
                    }
                }

                PHP_WRAP,
            'dynamic data provider reference' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\DataProvider;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    private const string PROVIDER = 'values';

                    #[DataProvider(self::PROVIDER)]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }

                    public static function values(): iterable
                    {
                        yield [1];
                    }
                }

                PHP_WRAP,
            'named TestWith data' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\Attributes\TestWith;
                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    #[TestWith(data: [1])]
                    public function testValue(int $value): void
                    {
                        $this->assertSame(1, $value);
                    }
                }

                PHP_WRAP,
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-data-providers');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported data provider case: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported data provider case: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
