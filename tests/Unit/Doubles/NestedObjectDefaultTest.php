<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;

final readonly class NestedObjectDefaultTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function nestedObjectDefaultsProduceAnAuthoringError(): void
    {
        Expect::that(fn(): object => $this->doubles->stub(NestedObjectDefaultTarget::class))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot reproduce the object default of parameter $values from '
                    . NestedObjectDefaultTarget::class
                    . '::run() in a proxy. Use an interface without object defaults instead.',
            );
    }

    #[Test]
    public function nestedScalarAndEnumDefaultsRemainValid(): void
    {
        $stub = $this->doubles->stub(NestedValueDefaultTarget::class);
        $default = new \ReflectionMethod($stub, 'run')->getParameters()[0]->getDefaultValue();

        Expect::that($default)->toBe(['values' => [null, 1, 'value', NestedDefaultChoice::First]]);
    }
}

interface NestedObjectDefaultTarget
{
    /** @param array<string, list<\stdClass>> $values */
    public function run(array $values = ['objects' => [new \stdClass()]]): void;
}

interface NestedValueDefaultTarget
{
    /** @param array<string, list<int|string|null|NestedDefaultChoice>> $values */
    public function run(array $values = ['values' => [null, 1, 'value', NestedDefaultChoice::First]]): void;
}

enum NestedDefaultChoice
{
    case First;
}
