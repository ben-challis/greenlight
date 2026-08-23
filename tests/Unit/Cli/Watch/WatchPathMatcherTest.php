<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\WatchPathMatcher;
use Greenlight\Expect\Expect;

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

        Expect::that($matcher->includesAdditionalFile('/project/templates/page.twig', false))->toBeTrue();
        Expect::that($matcher->includesAdditionalFile('/project/config/a.yaml', false))->toBeTrue();
        Expect::that($matcher->includesAdditionalFile('/project/config/app.yaml', false))->toBeFalse();
        Expect::that($matcher->includesAdditionalFile('/project/templates/cache/page.twig', false))
            ->because('an exclude pattern MUST have precedence over an include pattern')
            ->toBeFalse();
    }

    #[Test]
    public function exactInputsNeedNoIncludePatternButStillUseExclusions(): void
    {
        $matcher = new WatchPathMatcher('/project', ['**/*.yaml'], ['secrets/**']);

        Expect::that($matcher->includesAdditionalFile('/project/config/settings.json', true))->toBeTrue();
        Expect::that($matcher->includesAdditionalFile('/project/secrets/settings.json', true))->toBeFalse();
    }

    #[Test]
    public function absolutePatternsMatchInputsOutsideTheWorkingDirectory(): void
    {
        $matcher = new WatchPathMatcher('/project', ['/shared/**/*.sql'], []);

        Expect::that($matcher->includesAdditionalFile('/shared/migrations/one.sql', false))->toBeTrue();
        Expect::that($matcher->includesAdditionalFile('/other/migrations/one.sql', false))->toBeFalse();
    }

    #[Test]
    public function defaultInputsRemainPhpOnlyAndUseConfiguredExclusions(): void
    {
        $matcher = new WatchPathMatcher('/project', [], ['build/**']);

        Expect::that($matcher->includesDefaultPhpFile('/project/src/Example.php'))->toBeTrue();
        Expect::that($matcher->includesDefaultPhpFile('/project/src/template.twig'))->toBeFalse();
        Expect::that($matcher->includesDefaultPhpFile('/project/build/Example.php'))->toBeFalse();
    }

    #[Test]
    public function prunesOnlyPatternsThatExcludeACompleteDirectoryTree(): void
    {
        $matcher = new WatchPathMatcher('/project', [], ['templates/*', '**/cache/**']);

        Expect::that($matcher->excludesDirectory('/project/templates'))
            ->because('a single-star file pattern MUST not prune nested files')
            ->toBeFalse();
        Expect::that($matcher->excludesDirectory('/project/templates/cache'))
            ->because('a double-star directory suffix excludes the complete tree')
            ->toBeTrue();
    }
}
