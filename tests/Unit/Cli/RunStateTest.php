<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\RunState;
use Greenlight\Expect\Expect;

final class RunStateTest
{
    #[Test]
    public function roundTripsFailureSetsIncludingEmpty(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $state = RunState::forWorkingDirectory($directory);
        $expect = new Expect();

        $expect->that($state->failedTests())->toBeNull();

        $state->record(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']);
        $expect->that($state->failedTests())->toBe(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']);

        $state->record([]);
        $expect->that($state->failedTests())->toBe([]);
    }

    #[Test]
    public function corruptStateReadsAsAbsent(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $file = \sprintf(
            '%s/greenlight-state-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($directory), 0, 12),
        );
        \file_put_contents($file, 'not json at all');

        $expect = new Expect();
        $expect->that(RunState::forWorkingDirectory($directory)->failedTests())->toBeNull();

        \file_put_contents($file, '{"failed": "not a list"}');
        $expect->that(RunState::forWorkingDirectory($directory)->failedTests())->toBeNull();

        @\unlink($file);
    }
}
