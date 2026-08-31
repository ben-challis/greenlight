<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\Type;

/**
 * Resolves the Greenlight non-negative float PHPDoc type.
 *
 * @internal
 */
final class NonNegativeFloatTypeNodeResolverExtension implements TypeNodeResolverExtension
{
    #[\Override]
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (!$typeNode instanceof IdentifierTypeNode || $typeNode->name !== 'non-negative-float') {
            return null;
        }

        return new NonNegativeFloatType();
    }
}
