<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalRowsResolver;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\EnvironmentVariables;

final readonly class ApplicationTerminalRowsTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    public function linesEnvironmentVariableSetsTerminalRows(): void
    {
        $this->environment->set('LINES', '37');

        $rows = TerminalRowsResolver::resolve();

        Expect::that($rows)
            ->because('a positive LINES value MUST set the reporter terminal height')
            ->toBe(37);
    }
}
