<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

#[RequiresResource('analysis-process')]
final class PhpStanNativeMatcherOverrideTest
{
    private const string MESSAGE = 'Extension matcher "toBeInt" conflicts with a native expectation method. Rename the extension matcher.';

    #[Test]
    public function runtimeAndToolingRejectNativeMatcherNames(): void
    {
        $root = \dirname(__DIR__, 2);
        $config = FixturePath::get('PhpStanNativeMatcherOverride/greenlight.php');

        Expect::that(static fn() => MatcherMap::fromConfigFiles([$config]))
            ->toThrow(MatcherMapError::class, message: self::MESSAGE);

        $run = GreenlightCli::run($root, ['run', '--config=' . $config, '--workers=1', '--no-ansi']);
        Expect::that($run->exitCode)->not()->toBe(0);
        Expect::that($run->output())->toContain(self::MESSAGE);

        $helper = GreenlightCli::run($root, ['ide-helper', '--config=' . $config, '--no-ansi']);
        Expect::that($helper->exitCode)->not()->toBe(0);
        Expect::that($helper->output())->toContain(self::MESSAGE);

        $analysis = PhpSubprocess::run($root, [
            $root . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=' . FixturePath::get('PhpStanNativeMatcherOverride/probe.neon'),
            FixturePath::get('PhpStanNativeMatcherOverride/Probe.php'),
        ]);
        Expect::that($analysis->exitCode)->not()->toBe(0);
        Expect::that($analysis->output())->toContain('conflicts with a native expectation method. Rename the extension matcher.');
    }
}
