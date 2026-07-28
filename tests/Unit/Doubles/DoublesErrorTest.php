<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class DoublesErrorTest
{
    #[Test]
    public function defaultValueErrorsNameTheParameterAndMethod(): void
    {
        Expect::that(DoublesError::defaultValueNotReproducible('limit', 'Example', 'run')->getMessage())
            ->toBe('Doubles cannot reproduce the default value of parameter $limit from Example::run() in a proxy.');

        Expect::that(DoublesError::objectDefaultNotReproducible('clock', 'Example', 'run')->getMessage())
            ->toBe(
                'Doubles cannot reproduce the object default of parameter $clock from Example::run() in a proxy. '
                . 'Use an interface without object defaults instead.',
            );
    }

    #[Test]
    #[DataSet('defensiveDiagnostics')]
    public function defensiveDiagnosticsNameTheUnsupportedReflectionInput(
        string $factory,
        string $input,
        string $expected,
    ): void {
        $error = match ($factory) {
            'unsupported reflection type' => DoublesError::unsupportedReflectionType($input),
            'parent type without parent' => DoublesError::parentTypeWithoutParent($input),
            'unsupported nested reflection type' => DoublesError::unsupportedNestedReflectionType($input),
            'unresolvable default constant' => DoublesError::defaultConstantUnresolvable($input),
            default => Fail::because(\sprintf('Unknown doubles diagnostic factory "%s".', $factory)),
        };

        Expect::that($error->getMessage())
            ->because('the doubles diagnostic MUST identify the unsupported reflection input')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function defensiveDiagnostics(): iterable
    {
        yield 'unsupported reflection type' => [
            'unsupported reflection type',
            'ExampleReflectionType',
            'Unsupported reflection type ExampleReflectionType.',
        ];
        yield 'parent type without parent' => [
            'parent type without parent',
            'ExampleContext',
            'ExampleContext uses the parent type but has no parent class.',
        ];
        yield 'unsupported nested reflection type' => [
            'unsupported nested reflection type',
            'ExampleNestedType',
            'Unsupported nested reflection type ExampleNestedType.',
        ];
        yield 'unresolvable default constant' => [
            'unresolvable default constant',
            'mode',
            'Doubles cannot resolve the default constant of parameter $mode.',
        ];
    }
}
