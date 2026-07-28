<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class ArraySubsetShapeTest
{
    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $subset
     */
    #[Test]
    #[DataSet('mixedNestedShapes')]
    public function mixedNestedShapesFailAsValueMismatches(
        array $subject,
        array $subset,
        string $message,
    ): void {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that($subject)->toContainSubset($subset),
        );

        Expect::that($detail->message)
            ->because('a nested array and scalar MUST produce a matcher failure, not an internal type error')
            ->toBe($message);
    }

    /**
     * @return iterable<string, array{
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     non-empty-string,
     * }>
     */
    public static function mixedNestedShapes(): iterable
    {
        yield 'nested subset against scalar subject' => [
            ['config' => 'ready'],
            ['config' => ['enabled' => true]],
            "Expected ['config' => 'ready'] to contain the subset "
                . "['config' => ['enabled' => true]] (mismatched value at key 'config').",
        ];
        yield 'scalar subset against nested subject' => [
            ['config' => ['enabled' => true]],
            ['config' => 'ready'],
            "Expected ['config' => ['enabled' => true]] to contain the subset "
                . "['config' => 'ready'] (mismatched value at key 'config').",
        ];
    }
}
