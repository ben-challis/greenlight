<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Error paths through the real CLI: an unknown command, missing required
 * options on coverage:diff, an unreadable profile:report input, and an
 * unwritable ide-helper output path.
 */
final class CommandErrorsTest
{
    #[Test]
    public function unknownCommandExitsWithAUsageError(): void
    {
        [$exit, $output] = AcceptanceProject::runIn(\dirname(__DIR__, 2), ['bogus-command']);

        Expect::that($exit)->toBe(64)
            ->and($output)->toContain("Unknown command 'bogus-command'")
            ->and($output)->toContain('greenlight --help');
    }

    #[Test]
    public function coverageDiffWithoutBaselineOrCurrentIsAUsageError(): void
    {
        [$exit, $output] = AcceptanceProject::runIn(\dirname(__DIR__, 2), ['coverage:diff']);

        Expect::that($exit)->toBe(64)
            ->and($output)->toContain('coverage:diff requires --baseline=<path> and --current=<path>');
    }

    #[Test]
    public function profileReportWithAMissingInputFileFailsCleanly(): void
    {
        $project = AcceptanceProject::create('command-errors');

        try {
            [$exit, $output] = $project->run('profile:report', '--input=nowhere.jsonl');

            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('Could not read')
                ->and($output)->toContain('nowhere.jsonl');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function ideHelperWithAnUnwritableOutputPathFailsCleanly(): void
    {
        // Root bypasses directory write permissions, so chmod 0555 cannot
        // provoke the write failure.
        if (\function_exists('posix_getuid') && \posix_getuid() === 0) {
            throw new SkipTest('An unwritable directory cannot be staged when running as root.');
        }

        // A config without any matchers configured skips writing entirely
        // (IdeHelperTest covers that path), so this needs the shipped
        // PhpStanExtension fixture, whose config has matchers to render.
        $fixture = \dirname(__DIR__) . '/Fixture/PhpStanExtension';
        $readOnlyDirectory = \sys_get_temp_dir() . '/greenlight-ide-helper-ro-' . \bin2hex(\random_bytes(6));
        \mkdir($readOnlyDirectory, 0o555);

        try {
            [$exit, $output] = AcceptanceProject::runIn($fixture, [
                'ide-helper',
                '--output=' . $readOnlyDirectory . '/helper.php',
            ]);

            Expect::that($exit)->toBe(1)->and($output)->toContain('Could not write');
        } finally {
            \chmod($readOnlyDirectory, 0o755);
            AcceptanceProject::removeTree($readOnlyDirectory);
        }
    }
}
