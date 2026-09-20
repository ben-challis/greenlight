<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\Test;
use Greenlight\Tempest\TempestBridgeError;

use function Greenlight\expect;

final readonly class TempestBridgeErrorTest
{
    #[Test]
    public function reportsAnUnavailableFramework(): void
    {
        $error = TempestBridgeError::frameworkUnavailable();

        expect($error->getMessage())->toBe(
            'The Tempest framework is not available. TempestPlugin requires '
                . 'tempest/framework 3.18 or later in major version 3. Install the framework '
                . 'before you activate the plugin.',
        );
        expect($error->getPrevious())->toBeNull();
    }

    #[Test]
    public function reportsABootFailureWithItsCause(): void
    {
        $cause = new \RuntimeException('boot probe');
        $error = TempestBridgeError::bootFailed('/project', $cause);

        expect($error->getMessage())
            ->toBe('TempestPlugin could not boot the application at "/project": boot probe');
        expect($error->getPrevious())->toBe($cause);
    }

    #[Test]
    public function reportsAShutdownFailureWithItsCause(): void
    {
        $cause = new \RuntimeException('shutdown probe');
        $error = TempestBridgeError::shutdownFailed('/project', $cause);

        expect($error->getMessage())
            ->toBe('TempestPlugin could not shut down the application at "/project": shutdown probe');
        expect($error->getPrevious())->toBe($cause);
    }

    #[Test]
    public function reportsAServiceResolutionFailureWithItsCause(): void
    {
        $cause = new \RuntimeException('resolution probe');
        $error = TempestBridgeError::serviceResolutionFailed(ProbeService::class, $cause);

        expect($error->getMessage())->toBe(
            'The Tempest container could not resolve the parameter type "'
            . ProbeService::class
            . '": resolution probe',
        );
        expect($error->getPrevious())->toBe($cause);
    }

    #[Test]
    public function reportsAServiceTypeMismatch(): void
    {
        $error = TempestBridgeError::serviceTypeMismatch(ProbeService::class, new \stdClass());

        expect($error->getMessage())->toBe(
            'The Tempest container returned "stdClass" for the parameter type "'
            . ProbeService::class
            . '".',
        );
        expect($error->getPrevious())->toBeNull();
    }
}

interface ProbeService {}
