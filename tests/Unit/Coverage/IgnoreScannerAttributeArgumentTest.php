<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class IgnoreScannerAttributeArgumentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function coverageIgnoreNamesInsideAttributeArgumentsDoNotIgnoreCode(): void
    {
        $source = <<<'PHP'
            <?php
            final class Example
            {
                #[Metadata('value', CoverageIgnore::class)]
                public function covered(): int
                {
                    return 1;
                }
            }
            PHP;
        $file = $this->tempDirectory->path() . '/Example.php';
        \file_put_contents($file, $source);

        Expect::that(new IgnoreScanner()->ignoredLines($file))
            ->because('attribute arguments MUST NOT be interpreted as coverage attributes')
            ->toBe([]);
    }
}
