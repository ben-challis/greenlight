<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Ignore;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class IgnoreScannerStringBraceTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[DataSet('strings')]
    public function stringBracesDoNotChangeTheIgnoredDeclaration(string $expression): void
    {
        $source = <<<'PHP'
            <?php
            /** @codeCoverageIgnore */
            function ignored(string $name): string
            {
                $text = EXPRESSION;
                return $text;
            }
            function kept(): int
            {
                return 1;
            }
            PHP;
        $path = $this->temporaryDirectory->path() . '/strings.php';
        \file_put_contents($path, \str_replace('EXPRESSION', $expression, $source));

        $map = new CoverageMap([new FileCoverage($path, [], [5, 6, 10])]);
        $filtered = new IgnoreFilter()->apply($map);

        Expect::that($filtered->files()[$path] ?? null)->toBeInstanceOf(FileCoverage::class);
        Expect::that($filtered->files()[$path]->uncoveredLines)->toBe([10]);
    }

    /** @return iterable<string, array{string}> */
    public static function strings(): iterable
    {
        yield 'closing brace before a variable' => ['"}$name"'];
        yield 'closing brace after a variable' => ['"{$name}}"'];
        yield 'opening brace after a variable' => ['"{$name}{"'];
    }
}
