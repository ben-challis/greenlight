<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanTestMethodRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function testMethodsMustBePublicNonStaticAndConcrete(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestMethodProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Test;

            final class GoodTestMethodProbe
            {
                #[Test]
                public function testMethod(): void {}

                #[Test]
                #[DataRow([1])]
                public function testMethodWithDataSet(int $value): void
                {
                    echo $value;
                }

                #[Test]
                public function testMethodWithOptionalParameter(int $value = 1): void
                {
                    echo $value;
                }

                #[Test]
                public function testMethodWithVariadicParameter(string ...$values): void
                {
                    echo \implode('', $values);
                }
            }

            abstract class GoodAbstractProbe
            {
                abstract public function helper(): void;
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestMethodProbe;

            use Greenlight\Attribute\Test;

            final class BadTestMethodProbe
            {
                #[Test]
                protected function protectedTest(): void {}

                #[Test]
                public static function staticTest(): void {}

                #[Test]
                public function testWithoutDataSet(int $value): void
                {
                    echo $value;
                }
            }

            abstract class BadAbstractTestMethodProbe
            {
                #[Test]
                abstract public function abstractTest(): void;
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('test methods must be public non static and concrete')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(4);
        Expect::that($probe->messages())->toContain('protectedTest() cannot run because it is not public')
            ->toContain('staticTest() cannot run because it is static')
            ->toContain('abstractTest() cannot run because it is abstract')
            ->toContain('testWithoutDataSet() has required parameters but no #[DataRow] or #[DataSet] attribute');
    }
}
