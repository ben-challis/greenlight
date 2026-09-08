<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDataSetNullMethodTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function explicitNullMethodsSelectAndValidateLocalProviders(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightNullProviderMethodProbe;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class GoodNullProviderMethodProbe
            {
                private const LOCAL_METHOD = null;

                #[Test]
                #[DataSet('rows', null)]
                public function positionalNull(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet(method: null, provider: 'rows')]
                public function namedNull(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('rows', self::LOCAL_METHOD)]
                public function constantNull(int $value): void
                {
                    echo $value;
                }

                /** @return array{array{int}} */
                public static function rows(): array
                {
                    return [[1]];
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightNullProviderMethodProbe;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class BadNullProviderMethodProbe
            {
                #[Test]
                #[DataSet('rows', method: null)]
                public function incompatibleRow(string $value): void
                {
                    echo $value;
                }

                /** @return array{array{int}} */
                public static function rows(): array
                {
                    return [[1]];
                }
            }
            PHP,
        );

        Expect::that($probe->goodErrors)->toBe([]);
        Expect::that($probe->exitCode)->toBe(1);
        Expect::that($probe->errors)->toBe([
            'Data provider rows() row argument #1 for incompatibleRow() has type int, but the parameter requires string.',
        ]);
    }
}
