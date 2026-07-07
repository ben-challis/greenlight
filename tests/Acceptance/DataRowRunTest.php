<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Inline #[DataRow] rows through the real CLI: expansion into the plan,
 * execution across workers, and label filtering.
 */
final class DataRowRunTest
{
    #[Test]
    public function inlineRowsRunAndFilterByLabel(): void
    {
        $expect = new Expect();

        [$exit, $output] = $this->run('--workers=2');
        $expect->that($exit)->toBe(0)
            ->and($output)->toContain('Tests: 4, Passed: 4')
            ->and($output)->toContain('addsUp[small]')
            ->and($output)->toContain('addsUp[#1]')
            ->and($output)->toContain('acceptsWord[from attribute]')
            ->and($output)->toContain('acceptsWord[from provider]');

        [$exit, $output] = $this->run('--filter=*[from attribute]');
        $expect->that($exit)->toBe(0)->and($output)->toContain('Tests: 1, Passed: 1');
    }

    /**
     * @return array{int, string}
     */
    private function run(string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'run', '--reporter=plain'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        $command = \sprintf(
            'cd %s && %s 2>&1',
            \escapeshellarg($root . '/tests/Fixture/DataRows'),
            \implode(' ', $parts),
        );
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }
}
