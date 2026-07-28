<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Condition\PhpVersionLessThan;
use Greenlight\Core\Condition;
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
}
