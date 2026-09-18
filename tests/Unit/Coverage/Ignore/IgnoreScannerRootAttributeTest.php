<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Ignore;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class IgnoreScannerRootAttributeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function rootQualifiedShortAttributeIgnoresTheDeclaration(): void
    {
        $path = $this->tempDirectory->path() . '/subject.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            #[\CoverageIgnore]
            function ignored(): int
            {
                return 1;
            }
            PHP);

        expect(\array_keys(new IgnoreScanner()->ignoredLines($path)))
            ->because('a root-qualified CoverageIgnore attribute MUST ignore its declaration')
            ->toBe([3, 4, 5, 6]);
    }
}
