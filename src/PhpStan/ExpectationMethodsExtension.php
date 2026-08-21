<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\TemporalExpectation;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Gets native matcher methods and extension matcher closure signatures.
 * PHPStan uses these signatures to check dynamic methods. Different signatures
 * for one extension matcher name cause an analysis error.
 *
 * PHPStan and workers use the same procedure to load configuration files.
 * PHPStan creates configured expectation extensions in the PHPStan process.
 *
 * @internal
 */
final readonly class ExpectationMethodsExtension implements MethodsClassReflectionExtension
{
    public function __construct(
        private MatcherMapProvider $matcherMap,
        private ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     * @throws MatcherMapError
     */
    #[\Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (!\in_array($classReflection->getName(), [
            Expectation::class,
            TemporalExpectation::class,
            EventuallyExpectation::class,
            ConsistentlyExpectation::class,
        ], true)) {
            return false;
        }

        return $this->isNativeTemporalMatcher($classReflection, $methodName)
            || $this->matcherMap->get()->has($methodName);
    }

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     * @throws MatcherMapError
     */
    #[\Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        if ($this->isNativeTemporalMatcher($classReflection, $methodName)) {
            return new NativeMatcherMethod(
                $classReflection,
                $this->reflectionProvider->getClass(Expectation::class)->getNativeMethod($methodName),
            );
        }

        return new ExtensionMatcherMethod(
            $classReflection,
            $methodName,
            $this->matcherMap->get()->parameters($methodName),
        );
    }

    private function isNativeTemporalMatcher(ClassReflection $classReflection, string $methodName): bool
    {
        if (!\str_starts_with($methodName, 'to')
            || !\in_array($classReflection->getName(), [
                TemporalExpectation::class,
                EventuallyExpectation::class,
                ConsistentlyExpectation::class,
            ], true)
        ) {
            return false;
        }

        return $this->reflectionProvider
            ->getClass(Expectation::class)
            ->hasNativeMethod($methodName);
    }
}
