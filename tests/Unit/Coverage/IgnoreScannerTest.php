<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Ignore\IgnoreScanner;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final class IgnoreScannerTest
{
    #[Test]
    public function unreadableFileYieldsNoIgnoredLines(): void
    {
        Expect::that(new IgnoreScanner()->ignoredLines('/nonexistent/nope.php'))->toBe([]);
    }

    #[Test]
    public function fileWithoutMarkersYieldsNoIgnoredLines(): void
    {
        $source = <<<'PHP'
            <?php
            function plain(): int
            {
                return 1;
            }
            PHP;

        Expect::that($this->scan($source))->toBe([]);
    }

    #[Test]
    public function attributeIgnoresTheWholeMethod(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                public function kept(): int
                {
                    return 1;
                }

                #[CoverageIgnore]
                public function dropped(): int
                {
                    return 2;
                }
            }
            PHP;

        // Lines 10-13: the marked declaration, signature through closing brace.
        Expect::that($this->scan($source))->toBe([10, 11, 12, 13]);
    }

    #[Test]
    public function attributeMatchesQualifiedGroupedAndMultilineForms(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                #[\Greenlight\Attribute\CoverageIgnore]
                public function qualified(): int
                {
                    return 1;
                }

                #[Deprecated('x'), CoverageIgnore]
                public function grouped(): int
                {
                    return 2;
                }

                #[
                    CoverageIgnore,
                ]
                public function multiline(): int
                {
                    return 3;
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([5, 6, 7, 8, 11, 12, 13, 14, 19, 20, 21, 22]);
    }

    #[Test]
    public function unrelatedAttributesDoNotIgnore(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                #[Deprecated(reason: 'old', since: [1, 2])]
                public function kept(): int
                {
                    return 1;
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([]);
    }

    #[Test]
    public function attributeOnClassIgnoresTheWholeClass(): void
    {
        $source = <<<'PHP'
            <?php
            #[CoverageIgnore]
            final class Whole
            {
                public function inside(): int
                {
                    return 1;
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([3, 4, 5, 6, 7, 8, 9]);
    }

    #[Test]
    public function docblockAnnotationIgnoresTheFollowingDeclaration(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                /**
                 * @codeCoverageIgnore
                 */
                private function __construct() {}

                public function kept(): int
                {
                    return 1;
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([7]);
    }

    #[Test]
    public function startEndCommentsIgnoreTheEnclosedRange(): void
    {
        $source = <<<'PHP'
            <?php
            function partial(int $value): int
            {
                $kept = $value + 1;

                // @codeCoverageIgnoreStart
                if ($value > 100) {
                    $kept = 0;
                }
                // @codeCoverageIgnoreEnd

                return $kept;
            }
            PHP;

        Expect::that($this->scan($source))->toBe([6, 7, 8, 9, 10]);
    }

    #[Test]
    public function unmatchedStartIgnoresThroughEndOfFile(): void
    {
        $source = <<<'PHP'
            <?php
            $a = 1;
            // @codeCoverageIgnoreStart
            $b = 2;
            $c = 3;
            PHP;

        Expect::that($this->scan($source))->toBe([3, 4, 5]);
    }

    #[Test]
    public function strayEndIsANoOp(): void
    {
        $source = <<<'PHP'
            <?php
            $a = 1;
            // @codeCoverageIgnoreEnd
            $b = 2;
            PHP;

        Expect::that($this->scan($source))->toBe([]);
    }

    #[Test]
    public function trailingCommentIgnoresItsOwnLine(): void
    {
        $source = <<<'PHP'
            <?php
            function guarded(int $value): int
            {
                if ($value < 0) {
                    return 0; // @codeCoverageIgnore
                }

                return $value;
            }
            PHP;

        Expect::that($this->scan($source))->toBe([5]);
    }

    #[Test]
    public function bracesInStringsAndHeredocsDoNotConfuseRanges(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                #[CoverageIgnore]
                public function dropped(string $name): string
                {
                    $tpl = "closing } and opening { braces {$name}";

                    return <<<TXT
                        more } braces {
                        TXT . $tpl;
                }

                public function kept(): int
                {
                    return 1;
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([5, 6, 7, 8, 9, 10, 11, 12]);
    }

    #[Test]
    public function nestedAnonymousClassStaysInsideTheIgnoredRange(): void
    {
        $source = <<<'PHP'
            <?php
            final class A
            {
                /** @codeCoverageIgnore */
                public function dropped(): object
                {
                    return new class {
                        public function inner(): int
                        {
                            return 1;
                        }
                    };
                }
            }
            PHP;

        Expect::that($this->scan($source))->toBe([5, 6, 7, 8, 9, 10, 11, 12, 13]);
    }

    #[Test]
    public function bodylessSignatureIgnoresOnlyTheSignatureLines(): void
    {
        $source = <<<'PHP'
            <?php
            interface A
            {
                /** @codeCoverageIgnore */
                public function dropped(): int;

                public function kept(): int;
            }
            PHP;

        Expect::that($this->scan($source))->toBe([5]);
    }

    #[Test]
    public function annotationWithoutAFollowingDeclarationIgnoresItsOwnLineOnly(): void
    {
        $source = <<<'PHP'
            <?php
            // @codeCoverageIgnore
            $closure = static function (): int {
                return 1;
            };
            PHP;

        Expect::that($this->scan($source))->toBe([2]);
    }

    /**
     * @return list<int>
     */
    private function scan(string $source): array
    {
        $directory = new TempDirectory();

        try {
            $path = $directory->path() . '/fixture.php';
            \file_put_contents($path, $source);

            $lines = \array_keys(new IgnoreScanner()->ignoredLines($path));
            \sort($lines);

            return $lines;
        } finally {
            $directory->dispose();
        }
    }
}
