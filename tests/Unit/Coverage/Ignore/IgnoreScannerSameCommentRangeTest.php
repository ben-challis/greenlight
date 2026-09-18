<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Ignore;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class IgnoreScannerSameCommentRangeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function pairedMarkerNamesInOneCommentDoNotIgnoreTheRemainingFile(): void
    {
        $path = $this->tempDirectory->path() . '/subject.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            /*
             * A pair of "@codeCoverageIgnoreStart" and "@codeCoverageIgnoreEnd"
             * identifies a bounded range.
             */
            $kept = 1;
            PHP);

        expect(\array_keys(new IgnoreScanner()->ignoredLines($path)))
            ->because('range markers in one comment MUST close before later source lines')
            ->toBe([2, 3, 4, 5]);
    }
}
