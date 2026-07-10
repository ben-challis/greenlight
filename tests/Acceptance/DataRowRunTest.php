<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Inline #[DataRow] rows through the real CLI: expansion into the plan,
 * execution across workers, and label filtering.
 */
final class DataRowRunTest
{
    #[Test]
    public function inlineRowsRunAndFilterByLabel(): void
    {
        [$exit, $output] = $this->run('--workers=2');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('4 tests, 4 passed')
            ->and($output)->toContain('addsUp[small]')
            ->and($output)->toContain('addsUp[#1]')
            ->and($output)->toContain('acceptsWord[from attribute]')
            ->and($output)->toContain('acceptsWord[from provider]');

        [$exit, $output] = $this->run('--filter=*[from attribute]');
        Expect::that($exit)->toBe(0)->and($output)->toContain('1 test, 1 passed');
    }

    /**
     * @return array{int, string}
     */
    private function run(string ...$flags): array
    {
        return AcceptanceProject::runIn(\dirname(__DIR__) . '/Fixture/DataRows', ['run', '--reporter=plain', ...\array_values($flags)]);
    }
}
