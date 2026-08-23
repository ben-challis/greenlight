<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

/**
 * Checks named invalid prose samples and their valid counterparts.
 *
 * @internal
 */
final readonly class ProseCheckRuleProbe
{
    /**
     * @param non-empty-array<non-empty-string, array{
     *     rule: non-empty-string,
     *     invalid: non-empty-string,
     *     valid: non-empty-string
     * }> $cases
     */
    public static function assertBlocks(
        TemporaryDirectory $tempDirectory,
        array $cases,
    ): void {
        \ksort($cases);

        $invalidFiles = ProjectFiles::create($tempDirectory, 'blocking-rules/invalid-project');
        $validFiles = ProjectFiles::create($tempDirectory, 'blocking-rules/valid-project');

        foreach ($cases as $name => $case) {
            $filename = $name . '.md';
            $invalidFiles->write($filename, "# Sample\n\n" . $case['invalid'] . "\n");
            $validFiles->write($filename, "# Sample\n\n" . $case['valid'] . "\n");
        }

        $invalidResult = self::run($invalidFiles->directory);
        Expect::that($invalidResult->exitCode)->because('blocking rules reject all invalid prose cases')->toBe(1);

        foreach ($cases as $name => $case) {
            Expect::that($invalidResult->output())
                ->because('reports each rule with its invalid case filename')
                ->toContain($name . '.md:3: ' . $case['rule'] . ':');
        }

        $validResult = self::run($validFiles->directory);
        Expect::that($validResult->exitCode)->because('blocking rules accept all valid prose cases')->toBe(0);

        foreach (\array_keys($cases) as $name) {
            Expect::that($validResult->output())
                ->because('reports no diagnostic for each valid case filename')
                ->not()->toContain($name . '.md:');
        }
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
