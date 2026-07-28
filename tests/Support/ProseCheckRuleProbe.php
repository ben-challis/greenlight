<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

/**
 * Checks one invalid prose sample and its valid equivalent.
 *
 * @internal
 */
final readonly class ProseCheckRuleProbe
{
    public static function assertBlocks(
        TempDirectory $tempDirectory,
        string $rule,
        string $invalid,
        string $valid,
    ): void {
        $root = $tempDirectory->subdirectory('blocking-' . $rule) . '/project';
        \mkdir($root);
        $sample = $root . '/sample.md';

        \file_put_contents($sample, "# Sample\n\n" . $invalid . "\n");

        $invalidResult = self::run($root);
        Expect::that($invalidResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(1)
            ->and($invalidResult->output())->toContain($rule);

        \file_put_contents($sample, "# Sample\n\n" . $valid . "\n");

        $validResult = self::run($root);
        Expect::that($validResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(0)
            ->and($validResult->output())->not()->toContain($rule);
    }

    private static function run(string $root): ProcessResult
    {
        return Subprocess::run($root, [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/prose-check.php',
            'check',
            '--root=' . $root,
        ]);
    }
}
