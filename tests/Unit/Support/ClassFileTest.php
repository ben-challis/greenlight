<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\ClassFile;

use function Greenlight\expect;

final class ClassFileTest
{
    #[Test]
    public function returnsTheDeclaringFileForAUserClass(): void
    {
        expect(ClassFile::of(self::class))
            ->because('a user class has a reusable source path')
            ->toBe(__FILE__);
    }

    #[Test]
    public function rejectsAnInternalClassWithoutASourceFile(): void
    {
        expect()->calling(static fn(): string => ClassFile::of(\stdClass::class))
            ->because('a missing class source file fails explicitly')
            ->toThrow(\RuntimeException::class, message: 'Class "stdClass" does not have a source file.');
    }
}
