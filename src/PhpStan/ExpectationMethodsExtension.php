<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\TemporalExpectation;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Reflects matcher closure signatures so PHPStan can check dynamic methods.
 * Conflicting signatures for one matcher name fail analysis.
 *
 * Config files are loaded the same way workers load them, so plugin
 * constructors run inside the PHPStan process.
 */
final class ExpectationMethodsExtension implements MethodsClassReflectionExtension
{
    private ?MatcherMap $map = null;

    /**
     * @param list<string> $configFiles relative paths resolve against the
     *   directory PHPStan runs from
     */
    public function __construct(private readonly array $configFiles) {}

    #[\Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return \in_array($classReflection->getName(), [
            Expectation::class,
            TemporalExpectation::class,
            EventuallyExpectation::class,
            ConsistentlyExpectation::class,
        ], true)
            && $this->map()->has($methodName);
    }

    #[\Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new ExtensionMatcherMethod(
            $classReflection,
            $methodName,
            $this->map()->parameters($methodName),
        );
    }

    private function map(): MatcherMap
    {
        return $this->map ??= MatcherMap::fromConfigFiles($this->configFiles);
    }
}
