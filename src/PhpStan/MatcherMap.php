<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Expect\ExpectationExtensionError;

/**
 * Combines extension matchers from a set of Greenlight configuration files.
 * The matcher name identifies each entry.
 *
 * Two files can declare one matcher with the same signature. This usually
 * occurs when they register the same plugin. A different signature with the
 * same name causes an error. Static analysis requires one signature for each
 * name.
 *
 * This class does not contain PHPStan symbols. Thus, all components except the
 * adapter classes can operate outside a PHPStan process.
 *
 * @internal
 */
final readonly class MatcherMap
{
    /**
     * @param array<non-empty-string, \ReflectionFunction> $matchers
     */
    private function __construct(private array $matchers) {}

    /**
     * @param list<string> $configFiles Relative paths use the current
     *   directory
     *
     * @throws ConfigFileError
     * @throws MatcherMapError
     * @throws InvalidConfiguration
     */
    public static function fromConfigFiles(array $configFiles): self
    {
        $loader = new ConfigLoader();
        $matchers = [];
        $declaredIn = [];
        $nativeMethods = \array_fill_keys(\array_map(\strtolower(...), \get_class_methods(Expectation::class)), true);

        foreach ($configFiles as $file) {
            if (!\str_starts_with($file, '/')) {
                $file = \getcwd() . '/' . $file;
            }

            $definitions = $loader->loadFile($file)->build()->execution->plugins;

            foreach ($definitions as $definition) {
                if (!$definition->supports(ExpectationExtension::class)) {
                    continue;
                }

                $plugin = $definition->create();

                if (!$plugin instanceof ExpectationExtension) {
                    throw new \LogicException('The plugin definition capability check returned an invalid result.');
                }

                foreach ($plugin->matchers() as $name => $matcher) {
                    if (isset($nativeMethods[\strtolower($name)])) {
                        throw MatcherMapError::invalidExtension(ExpectationExtensionError::nativeMethod($name));
                    }

                    $reflection = new \ReflectionFunction($matcher);
                    $signature = self::signature($reflection);
                    $existingSignature = isset($matchers[$name]) ? self::signature($matchers[$name]) : null;

                    if ($existingSignature !== null && $existingSignature !== $signature) {
                        throw MatcherMapError::conflictingSignatures(
                            $name,
                            $declaredIn[$name],
                            $existingSignature,
                            $file,
                            $signature,
                        );
                    }

                    $matchers[$name] = $reflection;
                    $declaredIn[$name] ??= $file;
                }
            }
        }

        return new self($matchers);
    }

    public function has(string $name): bool
    {
        return isset($this->matchers[$name]);
    }

    /**
     * @return list<non-empty-string>
     */
    public function names(): array
    {
        return \array_keys($this->matchers);
    }

    /**
     * Returns the matcher parameters that a caller supplies to the expectation chain.
     *
     * The result does not include the first subject parameter. The chain
     * supplies this parameter.
     *
     * @return list<\ReflectionParameter>
     */
    public function parameters(string $name): array
    {
        $matcher = $this->matcher($name);

        return \array_slice($matcher->getParameters(), 1);
    }

    /**
     * Returns the subject parameter for an extension matcher.
     */
    public function subjectParameter(string $name): ?\ReflectionParameter
    {
        return $this->matcher($name)->getParameters()[0] ?? null;
    }

    private static function signature(\ReflectionFunction $matcher): string
    {
        $parts = \array_map(
            static fn(\ReflectionParameter $parameter): string => self::parameterSignature($parameter, 'default'),
            $matcher->getParameters(),
        );

        return \sprintf(
            '(%s): %s',
            \implode(', ', $parts),
            self::typeName($matcher->getReturnType(), self::scopeClass($matcher)),
        );
    }

    /**
     * Returns the declared matcher return type. An absent type remains unresolved.
     */
    public function returnType(string $name): ?\ReflectionType
    {
        return $this->matcher($name)->getReturnType();
    }

    private function matcher(string $name): \ReflectionFunction
    {
        if (!isset($this->matchers[$name])) {
            throw new \LogicException(\sprintf('No extension matcher named "%s" is known.', $name));
        }

        return $this->matchers[$name];
    }

    /**
     * Renders one caller parameter in source-code form.
     *
     * $defaultPlaceholder replaces a default value that source code cannot represent.
     */
    public static function parameterSignature(\ReflectionParameter $parameter, string $defaultPlaceholder): string
    {
        return \sprintf(
            '%s %s$%s%s',
            self::typeName($parameter->getType(), self::scopeClass($parameter->getDeclaringFunction())),
            $parameter->isVariadic() ? '...' : '',
            $parameter->getName(),
            !$parameter->isVariadic() && $parameter->isOptional() ? ' = ' . $defaultPlaceholder : '',
        );
    }

    /**
     * Renders a native reflection type in source-code form.
     *
     * Signature comparison and generated @method annotations use this form.
     *
     * @param ?\ReflectionClass<object> $scopeClass
     */
    public static function typeName(?\ReflectionType $type, ?\ReflectionClass $scopeClass = null): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(
                static fn(\ReflectionType $member): string => $member instanceof \ReflectionIntersectionType
                    ? '(' . self::typeName($member, $scopeClass) . ')'
                    : self::typeName($member, $scopeClass),
                $type->getTypes(),
            ));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return \implode('&', \array_map(
                static fn(\ReflectionType $member): string => self::typeName($member, $scopeClass),
                $type->getTypes(),
            ));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $nullable = $type->allowsNull() && !\in_array($type->getName(), ['mixed', 'null'], true);
        $qualifier = $type->isBuiltin() || \in_array($type->getName(), ['self', 'static', 'parent'], true) ? '' : '\\';

        return ($nullable ? '?' : '') . $qualifier . self::resolvedTypeName($type->getName(), $scopeClass);
    }

    /** @param ?\ReflectionClass<object> $scopeClass */
    private static function resolvedTypeName(string $name, ?\ReflectionClass $scopeClass): string
    {
        if (!\in_array($name, ['self', 'static', 'parent'], true) || !$scopeClass instanceof \ReflectionClass) {
            return $name;
        }

        if ($name === 'parent') {
            $parentClass = $scopeClass->getParentClass();
            $scopeClass = $parentClass === false ? null : $parentClass;
        }

        return $scopeClass instanceof \ReflectionClass
            ? '\\' . $scopeClass->getName()
            : 'mixed';
    }

    /** @return ?\ReflectionClass<object> */
    private static function scopeClass(\ReflectionFunctionAbstract $function): ?\ReflectionClass
    {
        return $function instanceof \ReflectionMethod
            ? $function->getDeclaringClass()
            : $function->getClosureScopeClass();
    }
}
