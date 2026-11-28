<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Documentation\PhpExample;

use Greenlight\Attribute\Test;
use Greenlight\Documentation\PhpExample\Checker;
use Greenlight\Documentation\PhpExample\Diagnostic;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ExampleSyntaxCheckTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function reportsEachSyntaxErrorAtItsSourceLine(): void
    {
        $root = $this->temporaryDirectory->subdirectory('project');
        \mkdir($root . '/docs');
        \file_put_contents($root . '/README.md', <<<'MARKDOWN'
            <!-- php-example {"example":"syntax","file":"A.php","mode":"file","tools":[]} -->
            ```php
            return true;
            ```

            <!-- php-example {"example":"syntax","file":"B.php","mode":"file","tools":[]} -->
            ```php
            $value = true;
            return );
            ```

            <!-- php-example {"example":"syntax","file":"C.php","mode":"file","tools":[]} -->
            ```php
            return ];
            ```
            MARKDOWN);

        $result = (new Checker())->check($root, 'phpstan', 'rector');

        expect(\array_map(
            static fn(Diagnostic $diagnostic): array => [$diagnostic->sourcePath, $diagnostic->line, $diagnostic->tool],
            $result->diagnostics,
        ))->toBe([
            ['README.md', 9, 'PHP syntax'],
            ['README.md', 14, 'PHP syntax'],
        ]);
    }
}
