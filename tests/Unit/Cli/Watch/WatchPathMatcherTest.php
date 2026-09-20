<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\WatchPathMatcher;

use function Greenlight\expect;

final class WatchPathMatcherTest
{
    #[Test]
    public function matchesSlashSeparatedPatternsRelativeToTheWorkingDirectory(): void
    {
        $matcher = new WatchPathMatcher(
            '/project',
            ['**/*.twig', 'config/?.yaml'],
            ['templates/cache/**'],
        );

        expect($matcher->includesAdditionalFile('/project/templates/page.twig', false))->toBeTrue();
        expect($matcher->includesAdditionalFile('/project/config/a.yaml', false))->toBeTrue();
        expect($matcher->includesAdditionalFile('/project/config/app.yaml', false))->toBeFalse();
        expect($matcher->includesAdditionalFile('/project/templates/cache/page.twig', false))
            ->because('an exclude pattern MUST have precedence over an include pattern')
            ->toBeFalse();
    }

    #[Test]
    public function exactInputsNeedNoIncludePatternButStillUseExclusions(): void
    {
        $matcher = new WatchPathMatcher('/project', ['**/*.yaml'], ['secrets/**']);

        expect($matcher->includesAdditionalFile('/project/config/settings.json', true))->toBeTrue();
        expect($matcher->includesAdditionalFile('/project/secrets/settings.json', true))->toBeFalse();
    }

    #[Test]
    public function absolutePatternsMatchInputsOutsideTheWorkingDirectory(): void
    {
        $matcher = new WatchPathMatcher('/project', ['/shared/**/*.sql'], []);

        expect($matcher->includesAdditionalFile('/shared/migrations/one.sql', false))->toBeTrue();
        expect($matcher->includesAdditionalFile('/other/migrations/one.sql', false))->toBeFalse();
    }

    #[Test]
    public function defaultInputsRemainPhpOnlyAndUseConfiguredExclusions(): void
    {
        $matcher = new WatchPathMatcher('/project', [], ['build/**']);

        expect($matcher->includesDefaultPhpFile('/project/src/Example.php'))->toBeTrue();
        expect($matcher->includesDefaultPhpFile('/project/src/template.twig'))->toBeFalse();
        expect($matcher->includesDefaultPhpFile('/project/build/Example.php'))->toBeFalse();
    }

    #[Test]
    public function prunesOnlyPatternsThatExcludeACompleteDirectoryTree(): void
    {
        $matcher = new WatchPathMatcher('/project', [], ['templates/*', '**/cache/**']);

        expect($matcher->excludesDirectory('/project/templates'))
            ->because('a single-star file pattern MUST not prune nested files')
            ->toBeFalse();
        expect($matcher->excludesDirectory('/project/templates/cache'))
            ->because('a double-star directory suffix excludes the complete tree')
            ->toBeTrue();
    }
}
