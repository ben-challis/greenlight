<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

#[RequiresResource('analysis-process')]
final readonly class IdeHelperTypeResolutionTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function generatedHelperResolvesNativeAndExtensionTypes(): void
    {
        $root = \dirname(__DIR__, 2);
        $helper = $this->temporaryDirectory->path() . '/helper.php';
        $generated = GreenlightCli::run($root, [
            'ide-helper',
            '--config=' . FixturePath::get('IdeHelperClassNames/greenlight.php'),
            '--output=' . $helper,
        ]);
        Expect::that($generated->exitCode)->toBe(0);

        $configuration = $this->temporaryDirectory->path() . '/phpstan.neon';
        \file_put_contents($configuration, "parameters:\n    level: 2\n");
        $analysis = PhpSubprocess::run($root, [
            $root . '/vendor/bin/phpstan',
            'analyse',
            '--no-progress',
            '--error-format=json',
            '--configuration=' . $configuration,
            '--autoload-file=' . $root . '/vendor/autoload.php',
            $helper,
        ]);

        Expect::that($analysis->exitCode)->because($analysis->output())->toBe(0);
    }
}
