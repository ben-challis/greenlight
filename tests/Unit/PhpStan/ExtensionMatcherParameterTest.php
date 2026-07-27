<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\ExtensionMatcherParameter;
use PHPStan\Type\VerbosityLevel;

final class ExtensionMatcherParameterTest
{
    #[Test]
    #[DataSet('parameters')]
    public function reflectedMatcherParametersExposeTheirPhpStanSignature(
        string $method,
        string $name,
        bool $optional,
        bool $variadic,
        string $type,
    ): void {
        $reflection = new \ReflectionMethod(self::class, $method)->getParameters()[0];
        $parameter = new ExtensionMatcherParameter($reflection);

        Expect::that($parameter->getName())
            ->because('extension matcher parameters MUST preserve their reflected signature')
            ->toBe($name)
            ->and($parameter->isOptional())
            ->toBe($optional)
            ->and($parameter->isVariadic())
            ->toBe($variadic)
            ->and($parameter->getType()->describe(VerbosityLevel::typeOnly()))
            ->toBe($type)
            ->and($parameter->passedByReference()->no())
            ->toBeTrue()
            ->and($parameter->getDefaultValue())
            ->toBeNull();
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, bool, bool, non-empty-string}>
     */
    public static function parameters(): iterable
    {
        yield 'required' => ['required', 'required', false, false, 'string'];
        yield 'optional' => ['optional', 'optional', true, false, 'int'];
        yield 'variadic' => ['variadic', 'variadic', true, true, 'float'];
        yield 'reference' => ['reference', 'reference', false, false, 'bool'];
    }

    public function required(string $required): string
    {
        return $required;
    }

    public function optional(int $optional = 1): int
    {
        return $optional;
    }

    /**
     * @return array<int|string, float>
     */
    public function variadic(float ...$variadic): array
    {
        return $variadic;
    }

    public function reference(bool &$reference): bool
    {
        return $reference;
    }
}
