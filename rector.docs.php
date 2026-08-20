<?php

declare(strict_types=1);

require_once __DIR__ . '/tools/rector-config.php';

return \greenlightRectorConfig(
    [__DIR__ . '/build/docs-php'],
    __DIR__ . '/build/cache/rector-docs',
    [
        Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector::class,
        Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector::class,
        Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector::class,
        Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector::class,
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector::class,
    ],
);
