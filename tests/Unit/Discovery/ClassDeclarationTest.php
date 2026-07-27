<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassDeclaration;
use Greenlight\Expect\Expect;

final class ClassDeclarationTest
{
    #[Test]
    public function theFullyQualifiedNameHandlesGlobalAndNamedNamespaces(): void
    {
        $global = new ClassDeclaration('', 'GlobalTest', 'class');
        $namespaced = new ClassDeclaration('Example\Tests', 'NamespacedTest', 'class');

        Expect::that($global->fqcn())
            ->because('the fully qualified name handles global and named namespaces')
            ->toBe('GlobalTest')
            ->and($namespaced->fqcn())
            ->toBe('Example\Tests\NamespacedTest');
    }
}
