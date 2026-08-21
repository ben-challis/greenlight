<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class IgnoreScannerRepeatedStartTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anAdditionalStartDoesNotNarrowTheActiveRange(): void
    {
        $path = $this->tempDirectory->path() . '/subject.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            $before = 1;
            // @codeCoverageIgnoreStart
            $first = 2;
            // @codeCoverageIgnoreStart
            $second = 3;
            // @codeCoverageIgnoreEnd
            $after = 4;
            PHP);

        Expect::that(\array_keys(new IgnoreScanner()->ignoredLines($path)))
            ->because('an additional start marker MUST keep the earliest active range boundary')
            ->toBe([3, 4, 5, 6, 7]);
    }
}
