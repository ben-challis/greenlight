<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanTestAttributePlacementRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function testMetadataOnMethodsRequiresTest(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestAttributePlacementProbe;

            use Greenlight\Attribute\Before;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Test;

            #[Group('class metadata')]
            final class GoodTestAttributePlacementProbe
            {
                #[Before]
                public function before(): void {}

                #[Test]
                #[Group('method metadata')]
                public function testMethod(): void {}

                public function helper(): void {}
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestAttributePlacementProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\NoExpectations;

            final class BadTestAttributePlacementProbe
            {
                #[Group('ignored')]
                #[DataRow([1])]
                public function dataHelper(int $value): void
                {
                    echo $value;
                }

                #[NoExpectations]
                public function assertionHelper(): void {}
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('test metadata on methods requires the test attribute')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(3)
            ->and($probe->messages())->toContain('#[Group] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::dataHelper() has no effect')
            ->toContain('#[DataRow] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::dataHelper() has no effect')
            ->toContain('#[NoExpectations] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::assertionHelper() has no effect');
    }
}
