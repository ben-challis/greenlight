<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\HarnessSandboxes;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final readonly class HarnessSandboxesTest
{
    public function __construct(
        private TemporaryDirectory $temporaryDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    public function usesTheDefaultSandboxes(): void
    {
        $path = $this->temporaryDirectory->path();
        \file_put_contents($path . '/probe.txt', 'contents');

        $this->environment->set('GREENLIGHT_SANDBOX_E2E', 'inside');

        TraceLog::add('temp:' . $path);

        Expect::that(\is_file($path . '/probe.txt'))->toBeTrue();
        Expect::that(\getenv('GREENLIGHT_SANDBOX_E2E'))->toBe('inside');
    }
}
