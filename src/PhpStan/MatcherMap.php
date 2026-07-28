<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\ExpectationExtension;

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
     */
    public static function fromConfigFiles(array $configFiles): self
    {
        $loader = new ConfigLoader();
        $matchers = [];
        $declaredIn = [];

        foreach ($configFiles as $file) {
            if (!\str_starts_with($file, '/')) {
                $file = \getcwd() . '/' . $file;
            }

            $plugins = $loader->loadFile($file)->build()->plugins;

            foreach ($plugins as $plugin) {
                if (!$plugin instanceof ExpectationExtension) {
                    continue;
                }

                foreach ($plugin->matchers() as $name => $matcher) {
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

        return '(' . \implode(', ', $parts) . ')';
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
            self::typeName($parameter->getType()),
            $parameter->isVariadic() ? '...' : '',
            $parameter->getName(),
            !$parameter->isVariadic() && $parameter->isOptional() ? ' = ' . $defaultPlaceholder : '',
        );
    }

    /**
     * Renders a native reflection type in source-code form.
     *
     * Signature comparison and generated @method annotations use this form.
     */
    public static function typeName(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(
                static fn(\ReflectionType $member): string => $member instanceof \ReflectionIntersectionType
                    ? '(' . self::typeName($member) . ')'
                    : self::typeName($member),
                $type->getTypes(),
            ));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return \implode('&', \array_map(self::typeName(...), $type->getTypes()));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $nullable = $type->allowsNull() && !\in_array($type->getName(), ['mixed', 'null'], true);

        return ($nullable ? '?' : '') . $type->getName();
    }
}
