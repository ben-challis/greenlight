<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Integration;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Integration\IntegrationFixtureError;

final readonly class IntegrationFixtureErrorTest
{
    #[Test]
    #[DataSet('providerMessages')]
    public function providerFailuresUseCompleteSentences(
        string $failureMessage,
        string $reportedMessage,
    ): void {
        $cause = new \RuntimeException($failureMessage);
        $error = IntegrationFixtureError::provider(self::class, $cause);

        Expect::that($error->getMessage())
            ->because('integration fixture provider failures MUST use complete diagnostic sentences')
            ->toBe(\sprintf(
                'Integration fixture provider "%s" failed: %s',
                self::class,
                $reportedMessage,
            ));
        Expect::that($error->getPrevious())
            ->because('the provider failure MUST remain available as the previous exception')
            ->toBe($cause);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerMessages(): iterable
    {
        yield 'empty message' => ['', 'No message was provided.'];
        yield 'missing terminal punctuation' => ['connection failed', 'connection failed.'];
        yield 'period' => ['connection failed.', 'connection failed.'];
        yield 'question mark' => ['connection failed?', 'connection failed?'];
        yield 'exclamation mark' => ['connection failed!', 'connection failed!'];
        yield 'trailing whitespace' => ["connection failed \n", 'connection failed.'];
    }

    #[Test]
    public function provisioningFailuresIncludeEveryCleanupFailure(): void
    {
        $cause = new \RuntimeException('database did not start');
        $error = IntegrationFixtureError::provisioning(
            'database',
            $cause,
            [
                ['database', new \RuntimeException('socket close failed')],
                ['network', new \RuntimeException('already closed?')],
            ],
        );

        Expect::that($error->getMessage())
            ->because('provisioning diagnostics MUST retain the primary and cleanup failures')
            ->toBe(
                "Integration fixture \"database\" failed to provision: database did not start.\n"
                . "Additionally, cleanup for integration fixture \"database\" failed: socket close failed.\n"
                . 'Additionally, cleanup for integration fixture "network" failed: already closed?',
            );
        Expect::that($error->getPrevious())
            ->because('the provisioning failure MUST remain available as the previous exception')
            ->toBe($cause);
    }

    #[Test]
    public function cleanupFailuresUseTheTeardownDiagnostic(): void
    {
        $error = IntegrationFixtureError::cleanup([
            ['database', new \RuntimeException('socket close failed')],
        ]);

        Expect::that($error->getMessage())
            ->because('teardown diagnostics MUST identify the fixture and cleanup failure')
            ->toBe(
                "Integration fixture teardown failed.\n"
                . 'Additionally, cleanup for integration fixture "database" failed: socket close failed.',
            );
    }

    #[Test]
    public function runFailuresRemainPrimaryWhenCleanupAlsoFails(): void
    {
        $cause = new \LogicException('run aborted');
        $error = IntegrationFixtureError::afterFailure(
            $cause,
            [['database', new \RuntimeException('socket close failed')]],
        );

        Expect::that($error->getMessage())
            ->because('run failure diagnostics MUST retain additional fixture cleanup failures')
            ->toBe(
                "The run failed with LogicException: run aborted.\n"
                . 'Additionally, cleanup for integration fixture "database" failed: socket close failed.',
            );
        Expect::that($error->getPrevious())
            ->because('the run failure MUST remain available as the previous exception')
            ->toBe($cause);
    }
}
