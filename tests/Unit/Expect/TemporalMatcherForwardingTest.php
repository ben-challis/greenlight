<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;

final class TemporalMatcherForwardingTest
{
    /**
     * @param non-empty-string $matcher
     * @param list<mixed> $arguments
     */
    #[Test]
    #[DataSet('nativeMatchers')]
    public function nativeMatchersForwardTheirSubjectAndArguments(
        mixed $subject,
        string $matcher,
        array $arguments,
    ): void {
        $clock = new FakePollingClock();

        ExpectationRuntime::withClock($clock, static function () use ($subject, $matcher, $arguments): void {
            $expectation = Expect::eventually(static fn(): mixed => $subject)->within(1.0);
            $expectation->__call($matcher, $arguments);
        });

        Expect::that($clock->sleeps)
            ->because('a passing temporal matcher MUST complete on its first observation')
            ->toBe([]);
    }

    #[Test]
    public function dataCoversEveryNativeTemporalMatcher(): void
    {
        $provided = [];

        foreach (self::nativeMatchers() as [, $matcher]) {
            $provided[] = $matcher;
        }

        $native = [];

        foreach (new \ReflectionClass(Expectation::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (\str_starts_with($method->getName(), 'to')) {
                $native[] = $method->getName();
            }
        }

        \sort($provided);
        \sort($native);

        Expect::that($provided)
            ->because('each native temporal matcher MUST have a forwarding case')
            ->toBe($native);
    }

    /**
     * @return iterable<string, array{mixed, non-empty-string, list<mixed>}>
     */
    public static function nativeMatchers(): iterable
    {
        yield 'identity' => [1, 'toBe', [1]];
        yield 'equality' => [['value' => 1], 'toEqual', [['value' => 1]]];
        yield 'canonical equality' => [['b' => 2, 'a' => 1], 'toEqualCanonicalizing', [['a' => 1, 'b' => 2]]];
        yield 'one of' => [2, 'toBeOneOf', [1, 2, 3]];
        yield 'in iterable' => [2, 'toBeIn', [[1, 2, 3]]];
        yield 'instance' => [new \stdClass(), 'toBeInstanceOf', [\stdClass::class]];
        yield 'true' => [true, 'toBeTrue', []];
        yield 'false' => [false, 'toBeFalse', []];
        yield 'null' => [null, 'toBeNull', []];
        yield 'array' => [[], 'toBeArray', []];
        yield 'string' => ['value', 'toBeString', []];
        yield 'integer' => [1, 'toBeInt', []];
        yield 'float' => [1.0, 'toBeFloat', []];
        yield 'boolean' => [true, 'toBeBool', []];
        yield 'callable' => [static fn(): null => null, 'toBeCallable', []];
        yield 'iterable' => [[1], 'toBeIterable', []];
        yield 'contains' => [['first', 'second'], 'toContain', ['second']];
        yield 'count' => [[1, 2], 'toHaveCount', [2]];
        yield 'empty' => [[], 'toBeEmpty', []];
        yield 'length' => ['abc', 'toHaveLength', [3]];
        yield 'key' => [['key' => 'value'], 'toHaveKey', ['key']];
        yield 'subset' => [['first' => 1, 'second' => 2], 'toContainSubset', [['first' => 1]]];
        yield 'greater than' => [2, 'toBeGreaterThan', [1]];
        yield 'greater than or equal' => [2, 'toBeGreaterThanOrEqual', [2]];
        yield 'less than' => [1, 'toBeLessThan', [2]];
        yield 'less than or equal' => [2, 'toBeLessThanOrEqual', [2]];
        yield 'within' => [10.1, 'toBeWithin', [0.2, 10.0]];
        yield 'pattern' => ['greenlight', 'toMatch', ['/green/']];
        yield 'prefix' => ['greenlight', 'toStartWith', ['green']];
        yield 'suffix' => ['greenlight', 'toEndWith', ['light']];
        yield 'JSON' => ['{"value":1}', 'toBeJson', []];
        yield 'matching JSON' => ['{"value":1}', 'toMatchJson', ['{"value":1}']];
        yield 'throwable' => [
            static function (): never {
                throw new \RuntimeException('expected');
            },
            'toThrow',
            [\RuntimeException::class],
        ];
    }
}
