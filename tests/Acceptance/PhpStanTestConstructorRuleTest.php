<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanTestConstructorRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function testConstructorsMustHaveResolvableShapes(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestConstructorProbe;

            use Greenlight\Attribute\Test;

            interface Service {}

            final class GoodTestConstructorProbe
            {
                public function __construct(
                    private Service $service,
                    private ?Service $nullableService,
                    private int $defaulted = 1,
                ) {}

                #[Test]
                public function testMethod(): void
                {
                    echo $this->service::class, \get_debug_type($this->nullableService), $this->defaulted;
                }
            }

            final class HelperWithScalarConstructor
            {
                public function __construct(private int $value) {}

                public function value(): int
                {
                    return $this->value;
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestConstructorProbe;

            use Greenlight\Attribute\Test;

            interface FirstService {}
            interface SecondService {}

            final class PrivateConstructorProbe
            {
                private function __construct() {}

                #[Test]
                public function testMethod(): void {}
            }

            final class InvalidParametersProbe
            {
                public function __construct(
                    int $scalar,
                    FirstService|SecondService $union,
                    object $object,
                ) {
                    echo $scalar, $union::class, $object::class;
                }

                #[Test]
                public function testMethod(): void {}
            }

            abstract class InheritedTestProbe
            {
                #[Test]
                public function inheritedTest(): void {}
            }

            final class InvalidInheritedTestConstructorProbe extends InheritedTestProbe
            {
                public function __construct(string $value)
                {
                    echo $value;
                }
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('test constructors must have resolvable shapes')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(5);
        Expect::that($probe->messages())->toContain('Greenlight cannot instantiate test class GreenlightTestConstructorProbe\PrivateConstructorProbe because its constructor is not public')
            ->toContain('Greenlight cannot resolve constructor parameter $scalar of test class GreenlightTestConstructorProbe\InvalidParametersProbe')
            ->toContain('Greenlight cannot resolve constructor parameter $union of test class GreenlightTestConstructorProbe\InvalidParametersProbe')
            ->toContain('Greenlight cannot resolve constructor parameter $object of test class GreenlightTestConstructorProbe\InvalidParametersProbe')
            ->toContain('Greenlight cannot resolve constructor parameter $value of test class GreenlightTestConstructorProbe\InvalidInheritedTestConstructorProbe');
    }

    #[Test]
    public function scalarVariadicConstructorParametersAreRejected(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestConstructorVariadicProbe;

            use Greenlight\Attribute\Test;

            interface Service {}

            final class GoodTestConstructorProbe
            {
                public function __construct(Service ...$services)
                {
                    echo \count($services);
                }

                #[Test]
                public function testMethod(): void {}
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightTestConstructorVariadicProbe;

            use Greenlight\Attribute\Test;

            final class ScalarVariadicConstructorProbe
            {
                public function __construct(int ...$values)
                {
                    echo \count($values);
                }

                #[Test]
                public function testMethod(): void {}
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('scalar variadic parameters cannot be resolved')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(1);
        Expect::that($probe->messages())->toContain(
            'Greenlight cannot resolve constructor parameter $values of test class '
                . 'GreenlightTestConstructorVariadicProbe\ScalarVariadicConstructorProbe',
        );
    }
}
