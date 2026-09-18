<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final readonly class TestIdZeroDataSetKeyTest
{
    #[Test]
    public function zeroDataSetKeyRemainsInTheRenderedId(): void
    {
        $id = new TestId('Acme\\DataSetTest', 'checksValue', '0');

        expect((string) $id)
            ->because('a rendered test ID MUST preserve the data-set key "0"')
            ->toBe('Acme\\DataSetTest::checksValue[0]');
    }
}
