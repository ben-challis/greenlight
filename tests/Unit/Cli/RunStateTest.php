<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
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

        try {
            Expect::that($state->failedTests())->toBeNull();

            Expect::that($state->record(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']))->toBeTrue();
            Expect::that($state->failedTests())->toBe(['Acme\AlphaTest::one', 'Acme\BetaTest::two[label]']);

            $state->record([]);
            Expect::that($state->failedTests())->toBe([]);
        } finally {
            @\unlink($this->stateFileFor($directory));
        }
    }

    #[Test]
    public function classDurationsRoundTripAndDefaultToEmpty(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $state = RunState::forWorkingDirectory($directory);

        try {
            Expect::that($state->classSeconds())->toBe([]);

            $state->record([], ['Acme\AlphaTest' => 1.25, 'Acme\BetaTest' => 0.5]);
            Expect::that($state->classSeconds())->toBe(['Acme\AlphaTest' => 1.25, 'Acme\BetaTest' => 0.5]);
        } finally {
            @\unlink($this->stateFileFor($directory));
        }
    }

    #[Test]
    public function corruptStateReadsAsAbsent(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $file = $this->stateFileFor($directory);
        \file_put_contents($file, 'not json at all');

        Expect::that(RunState::forWorkingDirectory($directory)->failedTests())->because('corrupt state reads as absent')->toBeNull();

        \file_put_contents($file, '{"failed": "not a list"}');
        Expect::that(RunState::forWorkingDirectory($directory)->failedTests())->because('corrupt state reads as absent')->toBeNull();

        @\unlink($file);
    }

    #[Test]
    public function recordWritesThroughATempFileAndLeavesNoneBehind(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $file = $this->stateFileFor($directory);

        try {
            RunState::forWorkingDirectory($directory)->record(['Acme\AlphaTest::one']);

            Expect::that(RunState::forWorkingDirectory($directory)->failedTests())->toBe(['Acme\AlphaTest::one']);
            Expect::that(\glob($file . '.tmp-*'))->toBe([]);
        } finally {
            @\unlink($file);
        }
    }

    #[Test]
    public function recordWhoseRenameFailsLeavesTheTargetUntouchedAndNoTempFile(): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $file = $this->stateFileFor($directory);

        // Put a nonempty directory at the target path. The temporary-file write
        // succeeds, but the final rename fails. This exercises the failure path
        // that MUST remove the temporary file.
        \mkdir($file);
        \file_put_contents($file . '/occupant.txt', 'keep');

        try {
            Expect::that(RunState::forWorkingDirectory($directory)->record(['Acme\AlphaTest::one']))->toBeFalse();

            Expect::that(\is_dir($file))->toBeTrue();
            Expect::that((string) \file_get_contents($file . '/occupant.txt'))->toBe('keep');
            Expect::that(\glob($file . '.tmp-*'))->toBe([]);
        } finally {
            @\unlink($file . '/occupant.txt');
            @\rmdir($file);
        }
    }

    #[Test]
    #[DataSet('nonFiniteDurations')]
    public function nonFiniteClassDurationsDoNotCreateState(float $seconds): void
    {
        $directory = '/fake/project-' . \bin2hex(\random_bytes(6));
        $file = $this->stateFileFor($directory);
        $state = RunState::forWorkingDirectory($directory);

        try {
            Expect::that($state->record([], ['Acme\ExampleTest' => $seconds]))
                ->because('JSON cannot store a non-finite class duration')
                ->toBeFalse()
                ->and(\file_exists($file))
                ->because('a failed state encoding MUST NOT create the state file')
                ->toBeFalse();
        } finally {
            @\unlink($file);
        }
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteDurations(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }

    private function stateFileFor(string $directory): string
    {
        return \sprintf(
            '%s/greenlight-state-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($directory), 0, 12),
        );
    }
}
