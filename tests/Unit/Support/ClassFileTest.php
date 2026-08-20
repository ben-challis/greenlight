<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\ClassFile;

final class ClassFileTest
{
    #[Test]
    public function returnsTheDeclaringFileForAUserClass(): void
    {
        Expect::that(ClassFile::of(self::class))
            ->because('a user class has a reusable source path')
            ->toBe(__FILE__);
    }

    #[Test]
    public function rejectsAnInternalClassWithoutASourceFile(): void
    {
        Expect::that(static fn(): string => ClassFile::of(\stdClass::class))
            ->because('a missing class source file fails explicitly')
            ->toThrow(\RuntimeException::class, message: 'Class "stdClass" does not have a source file.');
    }
}
