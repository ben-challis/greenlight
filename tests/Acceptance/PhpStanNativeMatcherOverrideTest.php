<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final class PhpStanNativeMatcherOverrideTest
{
    private const string MESSAGE = 'Extension matcher "toBeInt" conflicts with a native expectation method. Rename the extension matcher.';

    #[Test]
    public function runtimeAndToolingRejectNativeMatcherNames(): void
    {
        $root = \dirname(__DIR__, 2);
        $config = FixturePath::get('PhpStanNativeMatcherOverride/greenlight.php');

        expect()->calling(static fn() => MatcherMap::fromConfigFiles([$config]))
            ->toThrow(MatcherMapError::class, message: self::MESSAGE);

        $run = GreenlightCli::run($root, ['run', '--config=' . $config, '--workers=1', '--no-ansi']);
        expect($run->exitCode)->not()->toBe(0);
        expect($run->output())->toContain(self::MESSAGE);

        $helper = GreenlightCli::run($root, ['ide-helper', '--config=' . $config, '--no-ansi']);
        expect($helper->exitCode)->not()->toBe(0);
        expect($helper->output())->toContain(self::MESSAGE);

        $analysis = PhpSubprocess::run($root, [
            $root . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=' . FixturePath::get('PhpStanNativeMatcherOverride/probe.neon'),
            FixturePath::get('PhpStanNativeMatcherOverride/Probe.php'),
        ]);
        expect($analysis->exitCode)->not()->toBe(0);
        expect($analysis->output())->toContain('conflicts with a native expectation method. Rename the extension matcher.');
    }
}
