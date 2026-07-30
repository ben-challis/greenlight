<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class ClassFileParserDanglingTokenTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aDanglingClassTokenAtEndOfFileIsIgnored(): void
    {
        $file = $this->tempDirectory->path() . '/Dangling.php';
        \file_put_contents($file, '<?php class');

        Expect::that(ClassFileParser::declarationsIn($file))
            ->because('a dangling class token does not declare a class')
            ->toBe([]);
    }
}
