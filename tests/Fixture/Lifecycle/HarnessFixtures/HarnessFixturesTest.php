<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\HarnessFixtures;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final readonly class HarnessFixturesTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private EnvironmentSandbox $environment,
    ) {}

    #[Test]
    public function usesTheDefaultFixtures(): void
    {
        $path = $this->tempDirectory->path();
        \file_put_contents($path . '/probe.txt', 'contents');

        $this->environment->set('GREENLIGHT_FIXTURE_E2E', 'inside');

        TraceLog::add('temp:' . $path);

        Expect::that(\is_file($path . '/probe.txt'))->toBeTrue()
            ->and(\getenv('GREENLIGHT_FIXTURE_E2E'))->toBe('inside');
    }
}
