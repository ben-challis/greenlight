<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Test\TestMetadata;

/**
 * Turns a reflected test class into one TestMetadata per #[Test] method.
 *
 * Class-level attributes merge into each method: the method-level value wins
 * on conflict, groups merge as a union (class groups first), and isolation
 * applies when either level declares it.
 *
 * @internal
 */
final class MetadataFactory
{
    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<TestMetadata> in method declaration order
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

        $metadata = [];

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

            $metadata[] = new TestMetadata(
                $className,
                $methodName,
                $groups,
                $skip?->reason,
                $skipUnless?->condition,
                $retry?->times,
                $retry?->onlyOn,
                $timeout?->seconds,
                $classIsolated || $method->getAttributes(Isolated::class) !== [],
                $dataSet?->provider,
                $test->capture,
                $method->getAttributes(NoExpectations::class) !== [],
                $this->skipUnlessArguments($skipUnless, $where),
            );
        }

        return $metadata;
    }

    /**
     * Condition constructor arguments cross the wire to parallel workers, so
     * only scalars and null are allowed.
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
                    '#[SkipUnless] argument %d for condition "%s" must be a scalar or null, got %s.',
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
     */
    private function groupNames(\ReflectionClass|\ReflectionMethod $reflector, string $where): array
    {
        $names = [];

        foreach ($reflector->getAttributes(Group::class) as $attribute) {
            try {
                $names[] = $attribute->newInstance()->name;
            } catch (\Throwable $e) {
                throw DiscoveryError::invalidAttribute($where, $e);
            }
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
     */
    private function attributeInstance(\ReflectionClass|\ReflectionMethod $reflector, string $attribute, string $where): ?object
    {
        $attributes = $reflector->getAttributes($attribute);

        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance();
        } catch (\Throwable $e) {
            throw DiscoveryError::invalidAttribute($where, $e);
        }
    }
}
