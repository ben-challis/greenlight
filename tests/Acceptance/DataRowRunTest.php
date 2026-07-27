<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final class DataRowRunTest
{
    #[Test]
    public function inlineRowsRunAndFilterByLabel(): void
    {
        $result = $this->run('--workers=2');
        Expect::that($result->exitCode)->because('inline rows run and filter by label')->toBe(0)
            ->and($result->output())->toContain('4 tests, 4 passed')
            ->toContain('addsUp[small]')
            ->toContain('addsUp[#1]')
            ->toContain('acceptsWord[from attribute]')
            ->toContain('acceptsWord[from provider]');

        $result = $this->run('--filter=*[from attribute]');
        Expect::that($result->exitCode)->because('inline rows run and filter by label')->toBe(0)->and($result->output())->toContain('1 test, 1 passed');
    }

    private function run(string ...$flags): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/DataRows', ['run', '--reporter=plain', ...\array_values($flags)]);
    }
}
