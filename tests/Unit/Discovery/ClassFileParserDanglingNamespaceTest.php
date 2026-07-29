<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class ClassFileParserDanglingNamespaceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aDanglingNamespaceTokenAtEndOfFileIsIgnored(): void
    {
        $file = $this->tempDirectory->path() . '/DanglingNamespace.php';
        \file_put_contents($file, '<?php namespace');

        Expect::that(ClassFileParser::declarationsIn($file))
            ->because('a dangling namespace token does not declare a namespace or class')
            ->toBe([]);
    }
}
