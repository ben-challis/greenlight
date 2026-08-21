<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorPrivateDataProviderTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesPrivateDataProvidersUntouched(): void
    {
        $source = <<<'PHP_WRAP'
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

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'private-data-provider',
        );

        Expect::that($probe->changed)
            ->because('a private data provider MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
