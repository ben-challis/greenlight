<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final readonly class ToBeInDuplicateKeyTest
{
    #[Test]
    public function traversableKeysDoNotDiscardEarlierValues(): void
    {
        $values = static function (): \Generator {
            yield 'shared' => 'first';
            yield 'shared' => 'second';
        };

        expect('first')
            ->because('toBeIn() MUST inspect every traversable value regardless of its key')
            ->toBeIn($values());
    }
}
