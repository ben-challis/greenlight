<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final readonly class IdeHelperTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function writesALintableHelperAndSkipsWhenNothingIsConfigured(): void
    {
        $root = \dirname(__DIR__, 2);
        $target = $this->tempDirectory->path() . '/ide-helper.php';

        $result = GreenlightCli::run(FixturePath::get('PhpStanExtension'), ['ide-helper', '--output=' . $target]);
        expect($result->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);
        expect($result->output())->toContain('3 matchers');

        $helper = (string) \file_get_contents($target);
        expect($helper)->because('writes a lintable helper and skips when nothing is configured')->toContain('@method self toHaveDigestLength(int $length)')
            ->not()->toContain('@method Expectation<T> toBeWithin')
            ->toContain('@method Expectation<T> toHaveDigestLength(int $length)')
            ->toContain('abstract class TemporalExpectation');

        $lint = PhpSubprocess::run($root, ['-l', $target]);
        expect($lint->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);

        $result = GreenlightCli::run(FixturePath::get('ListTestsConfig'), ['ide-helper', '--output=' . $target . '.none']);
        expect($result->exitCode)->because('writes a lintable helper and skips when nothing is configured')->toBe(0);
        expect($result->output())->toContain('The configuration has no extension matchers');
        expect(\is_file($target . '.none'))->toBeFalse();
    }

    #[Test]
    public function invalidConfigurationFailsBeforeWritingAHelper(): void
    {
        $project = FixturePath::get('ConfigFiles/WrongReturn');
        $config = $project . '/greenlight.php';
        $result = GreenlightCli::run($project, ['ide-helper', '--no-ansi']);

        expect($result->exitCode)
            ->because('ide-helper MUST report configuration errors before it writes output')
            ->toBe(1);
        expect($result->stderr)
            ->toBe(
                \sprintf(
                    'greenlight: Configuration file "%s" returned string. It must return a '
                    . 'Greenlight\Config\GreenlightConfig instance. End the file with '
                    . '"return GreenlightConfig::create()->...;".',
                    $config,
                ),
            );
        expect($result->stdout)
            ->toBe('');
    }
}
