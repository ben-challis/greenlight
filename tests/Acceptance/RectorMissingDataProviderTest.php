<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorMissingDataProviderTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesMissingDataProvidersUntouched(): void
    {
        $source = <<<'PHP_WRAP'
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

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'missing-data-provider',
        );

        Expect::that($probe->changed)
            ->because('a missing data provider MUST remain unsupported')
            ->toBeFalse()
            ->and($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
