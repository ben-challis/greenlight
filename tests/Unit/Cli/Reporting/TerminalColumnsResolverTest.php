<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final readonly class TerminalColumnsResolverTest
{
    #[Test]
    #[DataSet('environments')]
    public function resolvesTheColumnCount(string $columns, string $expected): void
    {
        $root = \dirname(__DIR__, 4);
        $result = PhpSubprocess::run(
            $root,
            [
                '-n',
                '-d',
                'disable_functions=exec',
                '-r',
                <<<'PHP'
                require $argv[1];

                fwrite(STDOUT, (string) Greenlight\Cli\Reporting\TerminalColumnsResolver::resolve());
                PHP,
                $root . '/vendor/autoload.php',
            ],
            ['COLUMNS' => $columns],
        );

        expect($result->exitCode)->toBe(0);
        expect($result->stdout)->toBe($expected);
        expect($result->stderr)->toBe('');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function environments(): iterable
    {
        yield 'COLUMNS value' => ['132', '132'];
        yield 'empty COLUMNS without a probe' => ['', '80'];
        yield 'zero COLUMNS without a probe' => ['0', '80'];
        yield 'invalid COLUMNS without a probe' => ['wide', '80'];
    }
}
