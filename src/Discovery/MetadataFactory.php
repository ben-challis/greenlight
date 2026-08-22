<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Test\DataProvider;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SchedulingPolicy;
use Greenlight\Test\SkipPolicy;
use Greenlight\Test\TestDefinition;

/**
 * Merges class attributes into each method. A method value has priority in a
 * conflict. Groups combine with class groups first. Isolation applies if the
 * class or method declares it.
 *
 * @internal
 */
final class MetadataFactory
{
    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<TestDefinition> in method declaration order
     *
     * @throws DiscoveryError
     */
    public function forClass(\ReflectionClass $class): array
    {
        $className = $class->getName();
        $classGroups = $this->groupNames($class, $className);
        $classSkip = $this->attributeInstance($class, Skip::class, $className);
        $classSkipUnless = $this->attributeInstance($class, SkipUnless::class, $className);
        $classRetry = $this->attributeInstance($class, Retry::class, $className);
        $classTimeout = $this->attributeInstance($class, Timeout::class, $className);
        $classIsolated = $class->getAttributes(Isolated::class) !== [];
        $classAllowsParallel = $class->getAttributes(AllowParallel::class) !== [];
        $classResources = $this->resourceNames($class, $className);

        if ($classAllowsParallel && $classIsolated) {
            throw DiscoveryError::incompatibleAttributes($className, 'AllowParallel', 'Isolated');
        }

        $definitions = [];

        foreach ($class->getMethods() as $method) {
            if ($method->getAttributes(Test::class) === []) {
                continue;
            }

            $methodName = $method->getName();

            if (!$method->isPublic()) {
                throw DiscoveryError::testMethodNotRunnable($className, $methodName, 'it is not public');
            }

            if ($method->isStatic()) {
                throw DiscoveryError::testMethodNotRunnable($className, $methodName, 'it is static');
            }

            if ($method->isAbstract()) {
                throw DiscoveryError::testMethodNotRunnable($className, $methodName, 'it is abstract');
            }

            $where = $className . '::' . $methodName . '()';
            $test = $this->attributeInstance($method, Test::class, $where) ?? new Test();
            $skip = $this->attributeInstance($method, Skip::class, $where) ?? $classSkip;
            $skipUnless = $this->attributeInstance($method, SkipUnless::class, $where) ?? $classSkipUnless;
            $retry = $this->attributeInstance($method, Retry::class, $where) ?? $classRetry;
            $timeout = $this->attributeInstance($method, Timeout::class, $where) ?? $classTimeout;
            $dataSet = $this->attributeInstance($method, DataSet::class, $where);
            $groups = \array_values(\array_unique([...$classGroups, ...$this->groupNames($method, $where)]));
            $resources = \array_values(\array_unique([...$classResources, ...$this->resourceNames($method, $where)]));

            if ($classAllowsParallel && $method->getAttributes(Isolated::class) !== []) {
                throw DiscoveryError::incompatibleAttributes($className, 'AllowParallel', 'Isolated');
            }

            $definitions[] = new TestDefinition(
                $className,
                $methodName,
                $groups,
                new SkipPolicy(
                    $skip?->reason,
                    $skipUnless?->condition,
                    $this->skipUnlessArguments($skipUnless, $where),
                ),
                new RetryPolicy($retry?->times, $retry?->onlyOn),
                new DataProvider($dataSet?->provider, $dataSet?->providerClass),
                new ExecutionPolicy(
                    $timeout?->seconds,
                    $test->capture,
                    $method->getAttributes(NoExpectations::class) !== [],
                ),
                new SchedulingPolicy(
                    $classIsolated || $method->getAttributes(Isolated::class) !== [],
                    $resources,
                    $classAllowsParallel,
                ),
            );
        }

        return $definitions;
    }

    /**
     * Condition constructor arguments cross the wire to parallel workers.
     * Thus, constructor arguments can contain only scalars and null.
     *
     * @return list<scalar|null>
     *
     * @throws DiscoveryError
     */
    private function skipUnlessArguments(?SkipUnless $skipUnless, string $where): array
    {
        if (!$skipUnless instanceof SkipUnless) {
            return [];
        }

        $arguments = [];

        foreach ($skipUnless->arguments as $index => $argument) {
            if ($argument !== null && !\is_scalar($argument)) {
                throw DiscoveryError::invalidAttribute($where, new \InvalidArgumentException(\sprintf(
                    'Use a scalar or null for #[SkipUnless] argument %d of condition "%s". Received %s.',
                    $index + 1,
                    $skipUnless->condition,
                    \get_debug_type($argument),
                )));
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $reflector
     *
     * @return list<non-empty-string>
     * @throws DiscoveryError
     */
    private function groupNames(\ReflectionClass|\ReflectionMethod $reflector, string $where): array
    {
        $names = [];

        foreach ($reflector->getAttributes(Group::class) as $attribute) {
            $names[] = ErrorTrap::run(
                static fn() => $attribute->newInstance(),
                wrap: static fn(\Throwable $error): DiscoveryError =>
                    DiscoveryError::invalidAttribute($where, $error),
            )->name;
        }

        return $names;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $reflector
     *
     * @return list<non-empty-string>
     * @throws DiscoveryError
     */
    private function resourceNames(\ReflectionClass|\ReflectionMethod $reflector, string $where): array
    {
        $names = [];

        foreach ($reflector->getAttributes(RequiresResource::class) as $attribute) {
            $names[] = ErrorTrap::run(
                static fn() => $attribute->newInstance(),
                wrap: static fn(\Throwable $error): DiscoveryError =>
                    DiscoveryError::invalidAttribute($where, $error),
            )->name;
        }

        return $names;
    }

    /**
     * @template T of object
     *
     * @param \ReflectionClass<object>|\ReflectionMethod $reflector
     * @param class-string<T> $attribute
     *
     * @return T|null
     * @throws DiscoveryError
     */
    private function attributeInstance(\ReflectionClass|\ReflectionMethod $reflector, string $attribute, string $where): ?object
    {
        $attributes = $reflector->getAttributes($attribute);

        if ($attributes === []) {
            return null;
        }

        return ErrorTrap::run(
            static fn() => $attributes[0]->newInstance(),
            wrap: static fn(\Throwable $error): DiscoveryError =>
                DiscoveryError::invalidAttribute($where, $error),
        );
    }
}
