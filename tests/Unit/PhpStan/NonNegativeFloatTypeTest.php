<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\NonNegativeFloatType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\FloatType;
use PHPStan\Type\Type;

final class NonNegativeFloatTypeTest
{
    #[Test]
    #[DataSet('constantTypes')]
    public function acceptsOnlyNonNegativeConstants(Type $candidate, bool $accepted): void
    {
        $result = new NonNegativeFloatType()->accepts($candidate, strictTypes: true);

        Expect::that($result->yes())
            ->because('the non-negative float type MUST accept only non-negative constants')
            ->toBe($accepted);
    }

    #[Test]
    public function anUnknownFloatRangeIsNotDefinitelyAccepted(): void
    {
        $result = new NonNegativeFloatType()->accepts(new FloatType(), strictTypes: true);

        Expect::that($result->maybe())
            ->because('an unknown float can contain a negative value')
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{Type, bool}>
     */
    public static function constantTypes(): iterable
    {
        yield 'zero float' => [new ConstantFloatType(0.0), true];
        yield 'positive float' => [new ConstantFloatType(1.5), true];
        yield 'negative float' => [new ConstantFloatType(-1.5), false];
        yield 'not a number' => [new ConstantFloatType(\NAN), false];
        yield 'zero integer' => [new ConstantIntegerType(0), true];
        yield 'positive integer' => [new ConstantIntegerType(1), true];
        yield 'negative integer' => [new ConstantIntegerType(-1), false];
    }
}
