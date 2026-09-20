<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\ParameterDefaultSource\Values {
    const OPTION = 'option';

    final class Payload
    {
        /** @param array<string, mixed> $items */
        public function __construct(public array $items = [])
        {
            throw new \RuntimeException('The default constructor must not run.');
        }
    }

    final class Other {}

    function helper(): void {}

    #[\Attribute(\Attribute::TARGET_PARAMETER)]
    final readonly class ProbeAttribute
    {
        /** @param list<int> $values */
        public function __construct(public array $values) {}
    }
}

namespace Greenlight\Tests\Fixture\ParameterDefaultSource\First {
    use Greenlight\Tests\Fixture\ParameterDefaultSource\Values\{Payload as Value, Other, const OPTION as ALIAS, function helper};
    use Greenlight\Tests\Fixture\ParameterDefaultSource\Values\ProbeAttribute, DateTimeImmutable as Clock;
    use const PHP_INT_MAX as MAXIMUM, PHP_INT_MIN;

    $captured = 'value';
    $closure = static function () use ($captured): string { return "{$captured}"; };

    interface Nested
    {
        public function run(
            #[ProbeAttribute([1, 2])] string $before = 'text',
            object $value = new Value(items: ['brace' => '}', 'nested' => [new Other()]]),
        ): void;
    }

    trait Original { public function original(object $value = new Value()): void {} public function unrelated(object $value = new Other()): void {} }
    trait Wrapper { use Original { original as wrapped; } }
    class Host { use Wrapper { wrapped as alias; } }
    class Child extends Host {}

    interface OwnerA { public function run(object $value = new Value()): void; } interface OwnerB { public function run(object $value = new Other()): void; }
}

namespace Greenlight\Tests\Fixture\ParameterDefaultSource\Second {
    use stdClass as Value;

    interface Nested
    {
        public function run(object $value = new Value()): void;
    }
}

namespace Greenlight\Tests\Fixture\ParameterDefaultSource\Ambiguous {
    trait First { public function run(object $value = new \ArrayObject()): void {} } trait Second { public function run(object $value = new \stdClass()): void {} } class Selected { use First, Second { Second::run insteadof First; } } class OwnMethod { use First, Second { Second::run insteadof First; } public function run(object $value = new \DateTimeImmutable()): void {} }

    /** @return list<object> */
    function anonymousPair(): array { return [new class { public function run(object $value = new \ArrayObject()): void {} }, new class { public function run(object $value = new \stdClass()): void {} }]; }
}
