<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\ProjectFiles;

#[RequiresResource('analysis-process')]
final readonly class IdeHelperTypeResolutionTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function generatedMatcherTypesResolveInTheHelperNamespace(): void
    {
        $project = ProjectFiles::create($this->temporaryDirectory, 'helper');
        $map = MatcherMap::fromConfigFiles([FixturePath::get('PhpStanIdeHelperDnf/greenlight.php')]);
        $project->write('helper.php', IdeHelper::render($map));
        $project->write('phpstan.neon', "parameters:\n    level: 2\n    tmpDir: cache\n");
        $result = PhpSubprocess::run($project->directory, [
            \dirname(__DIR__, 2) . '/vendor/bin/phpstan',
            'analyse',
            '--configuration=' . $project->path('phpstan.neon'),
            '--no-progress',
            '--error-format=raw',
            $project->path('helper.php'),
        ]);

        Expect::that($result->exitCode)->because('PHPStan output: ' . $result->output())->toBe(0);
    }
}
