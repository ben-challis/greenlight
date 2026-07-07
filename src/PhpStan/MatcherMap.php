<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\ExpectationExtension;

/**
 * The union of every extension matcher declared across a set of greenlight
 * config files, keyed by matcher name. Two files may declare the same matcher
 * with the same signature (typically by registering the same plugin); the
 * same name with a different signature is refused, because static analysis
 * needs one signature per name.
 *
 * Free of PHPStan symbols on purpose: everything except the thin adapter
 * classes can run, and be tested, outside a PHPStan process.
 *
 * @internal
 */
final readonly class MatcherMap
{
    /**
     * @param array<non-empty-string, \ReflectionFunction> $matchers
     */
    private function __construct(
        private array $matchers,
    ) {}

    /**
     * @param list<string> $configFiles relative paths resolve against the
     *   current working directory
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

                    if (isset($matchers[$name]) && self::signature($matchers[$name]) !== $signature) {
                        throw MatcherMapError::conflictingSignatures(
                            $name,
                            $declaredIn[$name],
                            self::signature($matchers[$name]),
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
     * The parameters a caller passes on the expectation chain: the matcher
     * closure's parameters minus the leading subject, which the chain binds.
     *
     * @return list<\ReflectionParameter>
     */
    public function parameters(string $name): array
    {
        if (!isset($this->matchers[$name])) {
            throw new \LogicException(\sprintf('No extension matcher named "%s" is known.', $name));
        }

        return \array_slice($this->matchers[$name]->getParameters(), 1);
    }

    private static function signature(\ReflectionFunction $matcher): string
    {
        $parts = [];

        foreach (\array_slice($matcher->getParameters(), 1) as $parameter) {
            $parts[] = \sprintf(
                '%s %s$%s%s',
                self::typeName($parameter->getType()),
                $parameter->isVariadic() ? '...' : '',
                $parameter->getName(),
                !$parameter->isVariadic() && $parameter->isOptional() ? ' = default' : '',
            );
        }

        return '(' . \implode(', ', $parts) . ')';
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(self::typeName(...), $type->getTypes()));
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
