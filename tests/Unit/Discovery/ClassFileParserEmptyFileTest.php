<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ClassFileParserEmptyFileTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function emptyReadableFileContainsNoDeclarations(): void
    {
        $file = $this->tempDirectory->path() . '/EmptyTest.php';
        \file_put_contents($file, '');

        expect(\is_readable($file))
            ->because('the empty test file MUST be readable')
            ->toBeTrue();
        expect(ClassFileParser::declarationsIn($file))
            ->because('an empty test file MUST contain no declarations')
            ->toBe([]);
    }
}
