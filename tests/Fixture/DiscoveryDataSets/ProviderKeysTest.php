<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryDataSets;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class ProviderKeysTest
{
    #[Test]
    #[DataSet('stringKeys')]
    public function withStringKeys(string $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringKeys(): iterable
    {
        yield 'first case' => ['a'];

        yield 'second case' => ['b'];
    }

    #[Test]
    #[DataSet('integerKeys')]
    public function withIntegerKeys(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<int, array{int}>
     */
    public static function integerKeys(): iterable
    {
        return [[1], [2], [3]];
    }

    #[Test]
    #[DataSet('awkwardKeys')]
    public function withAwkwardKeys(string $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardKeys(): iterable
    {
        yield "tab\tseparated" => ['control-char key'];

        yield "\x80\x81" => ['invalid utf-8 key'];

        yield '' => ['empty key'];
    }
}
