<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDynamicDataProviderTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesDynamicDataProviderReferencesUntouched(): void
    {
        $source = <<<'PHP_WRAP'
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

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'dynamic-data-provider',
        );

        Expect::that($probe->changed)
            ->because('a dynamic data provider reference MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
