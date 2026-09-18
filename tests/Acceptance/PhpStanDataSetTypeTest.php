<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDataSetTypeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function externalProviderTypesPreserveClassStrings(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\DataSet;

            /** @param non-empty-string $provider */
            function greenlightAcceptLocalProvider(string $provider): DataSet
            {
                return new DataSet($provider);
            }

            /** @param class-string|null $providerClass */
            function greenlightAcceptProviderClass(?string $providerClass): void {}

            $dataSet = new DataSet(DateTimeImmutable::class, 'rows');
            greenlightAcceptProviderClass($dataSet->providerClass);
            greenlightAcceptLocalProvider('rows');
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\DataSet;

            /** @param non-empty-string $provider */
            function greenlightRejectExternalProvider(string $provider): DataSet
            {
                return new DataSet($provider, 'rows');
            }

            /** @param class-string|null $providerClass */
            function greenlightRejectProviderMethod(?string $providerClass): void {}

            $dataSet = new DataSet('rows');
            greenlightRejectProviderMethod($dataSet->provider);
            greenlightRejectExternalProvider('NotAClass');
            PHP,
        );

        Expect::value($probe->exitCode)
            ->because('DataSet external provider types MUST preserve class-strings')
            ->toBe(1);
        Expect::value($probe->goodPassed)->because('PHPStan messages: ' . $probe->messages())->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(2);
        Expect::value($probe->messages())->toContain('expects class-string, string given');
        Expect::value($probe->messages())->toContain('expects class-string|null, string given');
    }
}
