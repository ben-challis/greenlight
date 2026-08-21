<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanTestAttributePlacementRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Isolated;
            use Greenlight\Attribute\NoExpectations;
            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Skip;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableSet;

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

                #[DataSet('rows')]
                #[Isolated]
                #[RequiresResource('database')]
                #[Retry(1)]
                #[Skip('not ready')]
                #[SkipUnless(EnvironmentVariableSet::class, 'APP_ENV')]
                #[Timeout(1.0)]
                public function metadataHelper(int $value): void
                {
                    echo $value;
                }

                /**
                 * @return iterable<array{int}>
                 */
                public static function rows(): iterable
                {
                    yield [1];
                }
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('test metadata on methods requires the test attribute')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(10);
        Expect::that($probe->messages())->toContain('#[Group] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::dataHelper() has no effect')
            ->toContain('#[DataRow] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::dataHelper() has no effect')
            ->toContain('#[NoExpectations] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::assertionHelper() has no effect')
            ->toContain(
                '#[DataSet] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[Isolated] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[RequiresResource] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[Retry] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[Skip] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[SkipUnless] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            )
            ->toContain(
                '#[Timeout] on GreenlightTestAttributePlacementProbe\BadTestAttributePlacementProbe::metadataHelper() has no effect',
            );
    }
}
