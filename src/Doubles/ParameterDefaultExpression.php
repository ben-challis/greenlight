<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Copies object default expressions without running their constructors.
 * Names and magic constants retain their declaration context in the proxy.
 *
 * @internal
 */
final readonly class ParameterDefaultExpression
{
    /**
     * @param \ReflectionClass<object> $context
     * @throws InvalidDoubleUsage
     */
    public static function render(\ReflectionParameter $parameter, \ReflectionClass $context, bool $requireSource = false): ?string
    {
        // Reflection text identifies new expressions, but loses literal precision.
        $description = (string) $parameter;
        $separator = \strpos($description, ' = ');

        if (!$requireSource && ($separator === false
            || !\array_any(\PhpToken::tokenize('<?php ' . \substr($description, $separator + 3)), static fn(\PhpToken $token): bool => $token->is(\T_NEW)))
        ) {
            return null;
        }

        $source = ParameterDefaultSource::read($parameter);

        if ($source === null) {
            throw InvalidDoubleUsage::objectDefaultSourceUnavailable($parameter->name, $context->name, $parameter->getDeclaringFunction()->name);
        }

        $tokens = $source['tokens'];

        $significant = \array_values(\array_filter($tokens, static fn(\PhpToken $token): bool => !$token->isIgnorable()));
        $replacements = [];

        foreach ($significant as $index => $token) {
            if (isset($replacements[$token->pos])) {
                continue;
            }

            $previous = $significant[$index - 1] ?? null;
            $next = $significant[$index + 1] ?? null;
            $magic = match ($token->id) {
                \T_FILE => $source['file'],
                \T_DIR => \dirname($source['file']),
                \T_LINE => $token->line,
                \T_NS_C => $source['namespace'],
                \T_CLASS_C => $context->name,
                \T_TRAIT_C => $source['trait'],
                \T_FUNC_C => $source['method'],
                \T_METHOD_C => ($source['trait'] === '' ? $context->name : $source['trait']) . '::' . $source['method'],
                default => null,
            };

            if ($magic !== null) {
                $replacements[$token->pos] = \var_export($magic, true);

                continue;
            }

            if (!$token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
                continue;
            }

            if ($previous?->is([\T_DOUBLE_COLON, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) === true
                || ($next?->is(':') === true && $previous?->is(['(', ',']) === true)
                || \in_array(\strtolower($token->text), ['true', 'false', 'null'], true)
            ) {
                continue;
            }

            if ($previous?->is(\T_NEW) === true || $next?->is(\T_DOUBLE_COLON) === true) {
                $class = self::className($token->text, $source['namespace'], $source['imports'], $context);
                $replacements[$token->pos] = '\\' . $class;

                if ($previous?->is(\T_NEW) === true && \class_exists($class, false)) {
                    $constructor = new \ReflectionClass($class)->getConstructor();

                    if ($constructor?->isPrivate() === true) {
                        throw InvalidDoubleUsage::objectDefaultScopeUnavailable($parameter->name, $context->name, $parameter->getDeclaringFunction()->name);
                    }
                }

                $member = $significant[$index + 2] ?? null;

                if ($next?->is(\T_DOUBLE_COLON) === true && $member?->is('{') === true && \class_exists($class, false)
                    && new \ReflectionClass($class)->getReflectionConstants(\ReflectionClassConstant::IS_PRIVATE) !== []
                ) {
                    throw InvalidDoubleUsage::objectDefaultScopeUnavailable($parameter->name, $context->name, $parameter->getDeclaringFunction()->name);
                }

                if ($next?->is(\T_DOUBLE_COLON) === true && $member?->is(\T_STRING) === true && \class_exists($class, false)) {
                    $constant = new \ReflectionClass($class)->getReflectionConstant($member->text);

                    if ($constant instanceof \ReflectionClassConstant && $constant->isPrivate()) {
                        $value = $constant->getValue();

                        if (self::containsObject($value)) {
                            throw InvalidDoubleUsage::objectDefaultScopeUnavailable($parameter->name, $context->name, $parameter->getDeclaringFunction()->name);
                        }

                        $replacements[$token->pos] = '(' . \var_export($value, true) . ')';
                        $replacements[$next->pos] = '';
                        $replacements[$member->pos] = '';
                    }
                }

                continue;
            }

            $replacements[$token->pos] = self::constantName($token->text, $source['namespace'], $source['imports'], $source['constants'], $parameter);
        }

        return \implode('', \array_map(static fn(\PhpToken $token): string => $replacements[$token->pos] ?? $token->text, $tokens));
    }

    /**
     * @param array<string, string> $imports
     * @param \ReflectionClass<object> $context
     * @throws InvalidDoubleUsage
     */
    private static function className(string $name, string $namespace, array $imports, \ReflectionClass $context): string
    {
        if (\strtolower($name) === 'self') {
            return $context->name;
        }

        if (\strtolower($name) === 'parent') {
            $parent = $context->getParentClass();

            if ($parent === false) {
                throw InvalidDoubleUsage::parentTypeWithoutParent($context->name);
            }

            return $parent->name;
        }

        return self::qualifiedName($name, $namespace, $imports);
    }

    /** @param array<string, string> $imports */
    private static function qualifiedName(string $name, string $namespace, array $imports): string
    {
        if (\str_starts_with($name, '\\')) {
            return \substr($name, 1);
        }

        if (\str_starts_with(\strtolower($name), 'namespace\\')) {
            return \ltrim($namespace . '\\' . \substr($name, 10), '\\');
        }

        $parts = \explode('\\', $name, 2);
        $import = $imports[\strtolower($parts[0])] ?? null;

        if ($import !== null) {
            return $import . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }

        return \ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * @param array<string, string> $imports
     * @param array<string, string> $constants
     * @throws InvalidDoubleUsage
     */
    private static function constantName(string $name, string $namespace, array $imports, array $constants, \ReflectionParameter $parameter): string
    {
        if (\str_contains($name, '\\')) {
            return '\\' . self::qualifiedName($name, $namespace, $imports);
        }

        if (isset($constants[$name])) {
            return '\\' . $constants[$name];
        }

        $qualified = \ltrim($namespace . '\\' . $name, '\\');

        if (\defined($qualified)) {
            return '\\' . $qualified;
        }

        if (\defined($name)) {
            return '\\' . $name;
        }

        throw InvalidDoubleUsage::defaultConstantUnresolvable($parameter->name);
    }

    public static function containsObject(mixed $value): bool
    {
        if (\is_array($value)) {
            return \array_any($value, self::containsObject(...));
        }

        return \is_object($value) && !$value instanceof \UnitEnum;
    }
}
