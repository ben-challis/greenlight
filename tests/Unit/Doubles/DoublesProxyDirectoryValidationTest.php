<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;

final class DoublesProxyDirectoryValidationTest
{
    #[Test]
    public function emptyProxyDirectoryIsRejected(): void
    {
        Expect::that(static fn(): Doubles => new Doubles(''))
            ->because('an empty proxy directory MUST NOT resolve generated files from the filesystem root')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Proxy directory MUST NOT be empty.',
            );
    }
}
