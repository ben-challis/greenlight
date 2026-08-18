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

/**
 * Gets matcher closure signatures through reflection. PHPStan uses the
 * signatures to check dynamic methods. Different signatures for one matcher
 * name cause an analysis error.
 *
 * PHPStan and workers use the same procedure to load configuration files.
 * PHPStan loads the files in the PHPStan process. Thus, plugin constructors
 * run in that process.
 *
 * @internal
 */
final readonly class ExpectationMethodsExtension implements MethodsClassReflectionExtension
{
    public function __construct(private MatcherMapProvider $matcherMap) {}

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    #[\Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return \in_array($classReflection->getName(), [
            Expectation::class,
            TemporalExpectation::class,
            EventuallyExpectation::class,
            ConsistentlyExpectation::class,
        ], true)
            && $this->matcherMap->get()->has($methodName);
    }

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    #[\Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new ExtensionMatcherMethod(
            $classReflection,
            $methodName,
            $this->matcherMap->get()->parameters($methodName),
        );
    }
}
