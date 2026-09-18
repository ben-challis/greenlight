<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassDeclaration;

use function Greenlight\expect;

final class ClassDeclarationTest
{
    #[Test]
    public function theFullyQualifiedNameHandlesGlobalAndNamedNamespaces(): void
    {
        $global = new ClassDeclaration('', 'GlobalTest', 'class');
        $namespaced = new ClassDeclaration('Example\Tests', 'NamespacedTest', 'class');

        expect($global->fqcn())
            ->because('the fully qualified name handles global and named namespaces')
            ->toBe('GlobalTest');
        expect($namespaced->fqcn())
            ->toBe('Example\Tests\NamespacedTest');
    }
}
