<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Documentation\PhpExample;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Documentation\PhpExample\Checker;
use Greenlight\Documentation\PhpExample\DocumentationExampleError;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ExtractionPublicationTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[DataRow(['../escape.php'], label: 'parent path')]
    #[DataRow(['nested/../../escape.php'], label: 'nested parent path')]
    #[DataRow(['/absolute.php'], label: 'absolute path')]
    #[DataRow(['example.txt'], label: 'non-PHP extension')]
    public function invalidVirtualPathsPreserveThePreviousWorkspace(string $file): void
    {
        $root = $this->temporaryDirectory->subdirectory('project');
        \mkdir($root . '/docs');
        \file_put_contents($root . '/README.md', <<<'MARKDOWN'
            <!-- php-example {"example":"valid","file":"src/Example.php","mode":"file","tools":[]} -->
            ```php
            return true;
            ```
            MARKDOWN);

        $checker = new Checker();
        $checker->extract($root);
        $manifest = \file_get_contents($root . '/build/docs-php/manifest.json');
        Expect::that($manifest)->toBeString();

        $metadata = \json_encode([
            'example' => 'invalid',
            'file' => $file,
            'mode' => 'file',
            'tools' => [],
        ], \JSON_THROW_ON_ERROR);
        \file_put_contents(
            $root . '/docs/invalid.md',
            '<!-- php-example ' . $metadata . " -->\n```php\nreturn false;\n```\n",
        );

        Expect::that(static fn() => $checker->extract($root))
            ->toThrow(DocumentationExampleError::class, matching: '~^docs/invalid\.md:1: PHP example file ~');

        Expect::that(\file_get_contents($root . '/build/docs-php/manifest.json'))->toBe($manifest);
        Expect::that(\file_get_contents($root . '/build/docs-php/valid/src/Example.php'))
            ->toBe("<?php\nreturn true;\n");
        Expect::that(\is_dir($root . '/build/docs-php/invalid'))->toBeFalse();
        Expect::that(\glob($root . '/build/docs-php.next-*'))->toBe([]);
    }
}
