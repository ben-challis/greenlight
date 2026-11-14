<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Ignore;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class IgnoreScannerTrailingCommentTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function aTrailingMarkerAfterCodeIgnoresOnlyItsOwnLine(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                public function first(): int
                {
                    return 1;
                } // @codeCoverageIgnore

                public function second(): int
                {
                    return 2;
                }
            }
            PHP;

        expect($this->scan($source))
            ->because('a marker after code on the same line MUST NOT ignore the next declaration')
            ->toBe([7 => true]);
    }

    #[Test]
    public function aTrailingMarkerBeforeAFunctionIgnoresOnlyItsOwnLine(): void
    {
        $source = <<<'PHP'
            <?php
            $value = 1; /* @codeCoverageIgnore */
            function kept(): int
            {
                return 1;
            }
            PHP;

        expect($this->scan($source))
            ->because('a marker after a statement MUST NOT ignore the next function')
            ->toBe([2 => true]);
    }

    #[Test]
    public function aMarkerAtTheStartOfALineStillIgnoresTheDeclaration(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                /* note */ // @codeCoverageIgnore
                public function dropped(): int
                {
                    return 1;
                }
            }
            PHP;

        expect($this->scan($source))
            ->because('a marker after only comments on its line MUST ignore the next declaration')
            ->toBe([5 => true, 6 => true, 7 => true, 8 => true]);
    }

    /**
     * @return array<int, true>
     */
    private function scan(string $source): array
    {
        $path = $this->temporaryDirectory->path() . '/trailing.php';
        \file_put_contents($path, $source);

        return new IgnoreScanner()->ignoredLines($path);
    }
}
