<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class ArtifactRecoveryAttemptFloorTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aStaleAttemptMarkerDoesNotReduceTheResultAttempt(): void
    {
        $root = $this->tempDirectory->subdirectory('recovery-attempt-floor');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-attempt-floor');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\\EvidenceTest', 'crashesAfterReporting');

        $attachments = $store->forAttempt($id, 2, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'completed evidence');

        $recovered = $store->recover(new TestResult(
            $id,
            Outcome::Errored,
            0.0,
            0,
            attempts: 10,
        ));

        Expect::that($recovered->attempts)
            ->because('stale recovery metadata MUST NOT reduce a reported attempt count')
            ->toBe(10);
        Expect::that($recovered->attachments)
            ->toHaveCount(1);
        Expect::that($recovered->attachments[0]->name)
            ->toBe('evidence.txt');
        Expect::that((string) \file_get_contents($recovered->attachments[0]->path))
            ->toBe('completed evidence');
    }

    #[Test]
    #[DataSet('invalidAttemptMarkers')]
    public function anInvalidAttemptMarkerDoesNotInflateTheResultAttempt(string $marker): void
    {
        $root = $this->tempDirectory->subdirectory('invalid-recovery-attempt');
        $staging = $root . '/staging';
        $id = new TestId('Example\\EvidenceTest', 'crashesAfterReporting');
        $testDirectory = $staging . '/' . ArtifactStore::testDirectory($id);
        \mkdir($testDirectory, 0o700, true);
        \file_put_contents($testDirectory . '/.attempt', $marker);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-invalid-attempt'),
            new ArtifactConfiguration($root . '/published'),
        );

        $recovered = $store->recover(new TestResult(
            $id,
            Outcome::Errored,
            0.0,
            0,
            attempts: 3,
        ));

        Expect::that($recovered->attempts)
            ->because('corrupt recovery metadata MUST NOT inflate a reported attempt count')
            ->toBe(3);
    }

    #[Test]
    public function aMissingAttemptMarkerDoesNotLeakEngineDiagnostics(): void
    {
        $root = $this->tempDirectory->subdirectory('missing-recovery-attempt');
        $staging = $root . '/staging';
        $id = new TestId('Example\\EvidenceTest', 'crashesBeforeRetry');
        $testDirectory = $staging . '/' . ArtifactStore::testDirectory($id);
        \mkdir($testDirectory, 0o700, true);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-missing-attempt'),
            new ArtifactConfiguration($root . '/published'),
        );

        $recovered = ErrorTrap::run(
            static fn() => $store->recover(new TestResult(
                $id,
                Outcome::Errored,
                0.0,
                0,
                attempts: 3,
            )),
            $warning,
        );

        Expect::that($recovered->attempts)
            ->because('a missing recovery marker MUST preserve the reported attempt count')
            ->toBe(3);
        Expect::that($warning)
            ->because('a missing recovery marker MUST not leak an engine diagnostic')
            ->toBeNull();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAttemptMarkers(): iterable
    {
        yield 'overflowing decimal' => [\str_repeat('9', 30)];
        yield 'exponent notation' => ['1e6'];
        yield 'decimal fraction' => ['9.5'];
        yield 'trailing text' => ['12 attempts'];
    }
}
