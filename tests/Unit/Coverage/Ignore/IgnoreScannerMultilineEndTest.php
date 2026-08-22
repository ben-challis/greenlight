<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Ignore;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class IgnoreScannerMultilineEndTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function multilineEndMarkerIncludesItsCompleteComment(): void
    {
        $path = $this->tempDirectory->path() . '/subject.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            // @codeCoverageIgnoreStart
            $ignored = 1;
            /*
             * @codeCoverageIgnoreEnd
             */
            $kept = 2;
            PHP);

        Expect::that(\array_keys(new IgnoreScanner()->ignoredLines($path)))
            ->because('a multiline end marker MUST include its complete comment')
            ->toBe([2, 3, 4, 5, 6]);
    }
}
