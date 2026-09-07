<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDataRowNullLabelTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function explicitNullLabelsUsePositionKeysForDuplicateChecks(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightNullRowLabelProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Test;

            final class GoodNullRowLabelProbe
            {
                private const POSITION_LABEL = null;

                #[Test]
                #[DataRow([1], null)]
                #[DataRow(label: null, arguments: [2])]
                #[DataRow([3], self::POSITION_LABEL)]
                public function uniquePositions(int $value): void
                {
                    echo $value;
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightNullRowLabelProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Test;

            final class BadNullRowLabelProbe
            {
                private const POSITION_LABEL = null;

                #[Test]
                #[DataRow([1], null)]
                #[DataRow([2], '#0')]
                public function positionalNull(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataRow([1], '#1')]
                #[DataRow(label: null, arguments: [2])]
                public function namedNull(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataRow([1], self::POSITION_LABEL)]
                #[DataRow([2], '#0')]
                public function constantNull(int $value): void
                {
                    echo $value;
                }
            }
            PHP,
        );

        Expect::that($probe->goodErrors)->toBe([]);
        Expect::that($probe->exitCode)->toBe(1);
        Expect::that($probe->errors)->toBe([
            '#[DataRow] key "#0" occurs more than once on positionalNull().',
            '#[DataRow] key "#1" occurs more than once on namedNull().',
            '#[DataRow] key "#0" occurs more than once on constantNull().',
        ]);
    }
}
