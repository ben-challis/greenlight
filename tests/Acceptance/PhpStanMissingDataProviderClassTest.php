<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class PhpStanMissingDataProviderClassTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function missingExternalProviderClassIsReported(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightMissingProviderClassProbe;

            use Greenlight\Attribute\Test;

            final class GoodMissingProviderClassProbe
            {
                #[Test]
                public function passes(): void {}
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightMissingProviderClassProbe;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class BadMissingProviderClassProbe
            {
                #[Test]
                #[DataSet(MissingProviders::class, 'rows')]
                public function testValue(int $value): void
                {
                    echo $value;
                }
            }
            PHP,
        );

        expect($probe->exitCode)
            ->because('PHPStan rejects a data set that references a missing provider class')
            ->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(2);
        expect($probe->messages())->toContain(
            'Data provider class GreenlightMissingProviderClassProbe\MissingProviders referenced by testValue() does not exist.',
        );
    }
}
