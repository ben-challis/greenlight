<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Output;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final readonly class TerminalRowsResolverUnavailableExecTest
{
    #[Test]
    public function unavailableExecUsesDefaultRows(): void
    {
        $root = \dirname(__DIR__, 4);
        $result = Subprocess::run(
            $root,
            [
                \PHP_BINARY,
                '-n',
                '-d',
                'disable_functions=exec',
                '-r',
                <<<'PHP'
                require $argv[1];

                fwrite(STDOUT, (string) Greenlight\Cli\Output\TerminalRowsResolver::resolve());
                PHP,
                $root . '/vendor/autoload.php',
            ],
            ['LINES' => ''],
        );

        Expect::that($result->exitCode)
            ->because('an unavailable terminal probe MUST not fail row resolution')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('an unavailable terminal probe MUST use the default row count')
            ->toBe('24');
        Expect::that($result->stderr)
            ->toBe('');
    }
}
