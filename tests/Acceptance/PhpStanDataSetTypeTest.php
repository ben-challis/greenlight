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
    public function providerClassPreservesTheClassStringType(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\DataSet;

            /** @param class-string|null $providerClass */
            function greenlightAcceptProviderClass(?string $providerClass): void {}

            $dataSet = new DataSet(DateTimeImmutable::class, 'rows');
            greenlightAcceptProviderClass($dataSet->providerClass);
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\DataSet;

            /** @param class-string|null $providerClass */
            function greenlightRejectProviderMethod(?string $providerClass): void {}

            $dataSet = new DataSet('rows');
            greenlightRejectProviderMethod($dataSet->provider);
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('DataSet providerClass MUST preserve the class-string type')
            ->toBe(1);
        Expect::that($probe->goodPassed)->because('PHPStan messages: ' . $probe->messages())->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())->toContain('expects class-string|null, string given');
    }
}
