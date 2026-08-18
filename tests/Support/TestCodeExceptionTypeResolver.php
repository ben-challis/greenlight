<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\Exceptions\DefaultExceptionTypeResolver;
use PHPStan\Rules\Exceptions\ExceptionTypeResolver;

/**
 * Makes exceptions unchecked in Greenlight test code.
 *
 * @internal
 */
final readonly class TestCodeExceptionTypeResolver implements ExceptionTypeResolver
{
    public function __construct(private DefaultExceptionTypeResolver $defaultResolver) {}

    #[\Override]
    public function isCheckedException(string $className, Scope $scope): bool
    {
        $namespace = $scope->getNamespace();
        $testDirectory = \dirname(__DIR__) . \DIRECTORY_SEPARATOR;

        if (
            $namespace === 'Greenlight\\Tests'
            || \str_starts_with($namespace ?? '', 'Greenlight\\Tests\\')
            || \str_starts_with($scope->getFile(), $testDirectory)
        ) {
            return false;
        }

        return $this->defaultResolver->isCheckedException($className, $scope);
    }
}
