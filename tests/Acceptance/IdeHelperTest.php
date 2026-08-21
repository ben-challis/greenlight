<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\Subprocess;

final readonly class IdeHelperTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function writesALintableHelperAndSkipsWhenNothingIsConfigured(): void
    {
        $root = \dirname(__DIR__, 2);
        $target = $this->tempDirectory->path() . '/ide-helper.php';

        $result = GreenlightCli::run($root . '/tests/Fixture/PhpStanExtension', ['ide-helper', '--output=' . $target]);
        Expect::that($result->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);
        Expect::that($result->output())->toContain('3 matchers');

        $helper = (string) \file_get_contents($target);
        Expect::that($helper)->because('writes a lintable helper and skips when nothing is configured')->toContain('@method self toHaveDigestLength(int $length)')
            ->toContain('@mixin Expectation')
            ->toContain('abstract class TemporalExpectation');

        $lint = Subprocess::run($root, [\PHP_BINARY, '-l', $target]);
        Expect::that($lint->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);

        $result = GreenlightCli::run($root . '/tests/Fixture/ListTestsConfig', ['ide-helper', '--output=' . $target . '.none']);
        Expect::that($result->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);
        Expect::that($result->output())->toContain('The configuration has no extension matchers');
        Expect::that(\is_file($target . '.none'))->toBeFalse();
    }

    #[Test]
    public function invalidConfigurationFailsBeforeWritingAHelper(): void
    {
        $project = \dirname(__DIR__) . '/Fixture/ConfigFiles/WrongReturn';
        $config = $project . '/greenlight.php';
        $result = GreenlightCli::run($project, ['ide-helper', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('ide-helper MUST report configuration errors before it writes output')
            ->toBe(1);
        Expect::that($result->stderr)
            ->toBe(
                \sprintf(
                    'greenlight: Configuration file "%s" returned string. It must return a '
                    . 'Greenlight\Config\GreenlightConfig instance. End the file with '
                    . '"return GreenlightConfig::create()->...;".',
                    $config,
                ),
            );
        Expect::that($result->stdout)
            ->toBe('');
    }
}
