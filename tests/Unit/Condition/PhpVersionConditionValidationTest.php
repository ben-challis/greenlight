<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\Condition;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Condition\PhpVersionLessThan;
use Greenlight\Expect\Expect;

final readonly class PhpVersionConditionValidationTest
{
    /**
     * @param \Closure(): Condition $create
     */
    #[Test]
    #[DataSet('emptyVersionConditions')]
    public function rejectsAnEmptyPhpVersion(\Closure $create): void
    {
        Expect::that($create)
            ->because('a PHP version condition MUST identify its comparison version')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'PHP version MUST NOT be empty.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(): Condition}>
     */
    public static function emptyVersionConditions(): iterable
    {
        yield 'at least' => [static fn(): PhpVersionAtLeast => new PhpVersionAtLeast('')];
        yield 'less than' => [static fn(): PhpVersionLessThan => new PhpVersionLessThan('')];
    }

    /**
     * @param \Closure(): Condition $create
     */
    #[Test]
    #[DataSet('zeroVersionConditions')]
    public function acceptsZeroAsAPhpVersion(\Closure $create, bool $expected): void
    {
        Expect::that($create()->isSatisfied())
            ->because('the non-empty version "0" MUST retain PHP version comparison semantics')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{\Closure(): Condition, bool}>
     */
    public static function zeroVersionConditions(): iterable
    {
        yield 'at least' => [static fn(): PhpVersionAtLeast => new PhpVersionAtLeast('0'), true];
        yield 'less than' => [static fn(): PhpVersionLessThan => new PhpVersionLessThan('0'), false];
    }
}
