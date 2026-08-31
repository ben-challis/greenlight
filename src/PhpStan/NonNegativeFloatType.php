<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\AcceptsResult;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Represents a float that is greater than or equal to zero.
 *
 * @internal
 */
final class NonNegativeFloatType extends FloatType
{
    #[\Override]
    public function accepts(Type $type, bool $strictTypes): AcceptsResult
    {
        if ($type->isFloat()->yes() || $type->isInteger()->yes()) {
            return $this->isSuperTypeOf($type)->toAcceptsResult();
        }

        if ($type instanceof CompoundType) {
            return $type->isAcceptedBy($this, $strictTypes);
        }

        return AcceptsResult::createNo();
    }

    #[\Override]
    public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
    {
        if ($type instanceof self) {
            return IsSuperTypeOfResult::createYes();
        }

        if ($type instanceof ConstantFloatType) {
            return IsSuperTypeOfResult::createFromBoolean(
                !\is_nan($type->getValue()) && $type->getValue() >= 0.0,
            );
        }

        if ($type instanceof ConstantIntegerType) {
            return IsSuperTypeOfResult::createFromBoolean($type->getValue() >= 0);
        }

        if ($type instanceof IntegerRangeType) {
            return IntegerRangeType::fromInterval(0, null)->isSuperTypeOf($type);
        }

        if ($type->isFloat()->yes() || $type->isInteger()->yes()) {
            return IsSuperTypeOfResult::createMaybe();
        }

        if ($type instanceof CompoundType) {
            return $type->isSubTypeOf($this);
        }

        return IsSuperTypeOfResult::createNo();
    }

    #[\Override]
    public function describe(VerbosityLevel $level): string
    {
        return 'non-negative-float';
    }

    #[\Override]
    public function toAbsoluteNumber(): Type
    {
        return $this;
    }

    #[\Override]
    public function toPhpDocNode(): TypeNode
    {
        return new IdentifierTypeNode('non-negative-float');
    }
}
