<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\DynamicPrivateConstantDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\PrivateConstructorDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\PrivateObjectConstantDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TrackedValue;

use function Greenlight\expect;

final readonly class ObjectDefaultBoundaryTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function defaultsWithoutReadableSourceFailWithoutRunningConstructors(): void
    {
        TrackedValue::$constructions = 0;
        $name = 'EvaluatedObjectDefault' . \bin2hex(\random_bytes(6));
        $type = __NAMESPACE__ . '\\' . $name;
        eval(\sprintf(
            <<<'PHP'
                namespace Greenlight\Tests\Unit\Doubles;

                interface %s
                {
                    public function run(
                        \Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TrackedValue $value =
                            new \Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TrackedValue('default'),
                    ): void;
                }
                PHP,
            $name,
        ));
        $stub = new \ReflectionMethod(Doubles::class, 'stub');

        expect()->calling(fn(): mixed => $stub->invoke($this->doubles, $type))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot read the object default of parameter "$value" from "'
                    . $type . '::run()". Declare the method on a separate line in a readable PHP file.',
            );
        expect(TrackedValue::$constructions)->toBe(0);
    }

    #[Test]
    public function privateConstructorsHaveAnExplicitDiagnostic(): void
    {
        expect()->calling(fn(): object => $this->doubles->stub(PrivateConstructorDefaults::class))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot access the object default of parameter "$value" from "'
                    . PrivateConstructorDefaults::class
                    . '::run()" in a proxy. Use a public constructor and accessible constants.',
            );
    }

    #[Test]
    public function dynamicPrivateConstantsHaveAnExplicitDiagnostic(): void
    {
        expect()->calling(fn(): object => $this->doubles->stub(DynamicPrivateConstantDefaults::class))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot access the object default of parameter "$value" from "'
                    . DynamicPrivateConstantDefaults::class
                    . '::run()" in a proxy. Use a public constructor and accessible constants.',
            );
    }

    #[Test]
    public function privateObjectConstantsHaveAnExplicitDiagnostic(): void
    {
        expect()->calling(fn(): object => $this->doubles->stub(PrivateObjectConstantDefaults::class))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot access the object default of parameter "$value" from "'
                    . PrivateObjectConstantDefaults::class
                    . '::run()" in a proxy. Use a public constructor and accessible constants.',
            );
    }
}
