<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

/**
 * Checks one invalid prose sample and its valid equivalent.
 *
 * @internal
 */
final readonly class ProseCheckRuleProbe
{
    public static function assertBlocks(
        TemporaryDirectory $tempDirectory,
        string $rule,
        string $invalid,
        string $valid,
    ): void {
        $files = ProjectFiles::create($tempDirectory, 'blocking-' . $rule . '/project');
        $root = $files->directory;

        $files->write('sample.md', "# Sample\n\n" . $invalid . "\n");

        $invalidResult = self::run($root);
        Expect::that($invalidResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(1);
        Expect::that($invalidResult->output())->toContain($rule);

        $files->write('sample.md', "# Sample\n\n" . $valid . "\n");

        $validResult = self::run($root);
        Expect::that($validResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(0);
        Expect::that($validResult->output())->not()->toContain($rule);
    }

    private static function run(string $root): ProcessResult
    {
        return PhpSubprocess::run($root, [
            \dirname(__DIR__, 2) . '/tools/prose-check.php',
            'check',
            '--root=' . $root,
        ]);
    }
}
