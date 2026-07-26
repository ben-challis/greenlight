<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Resource;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Execution\Resource\MachineResourceCoordinator;
use Greenlight\Execution\Resource\MachineResourceEnvironment;
use Greenlight\Execution\Resource\MachineResourcePermit;
use Greenlight\Execution\Resource\ResourceCoordinationError;

use function Greenlight\expect;

final readonly class MachineResourceCoordinatorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function processesShareTheConfiguredCapacity(): void
    {
        $first = $this->coordinator(['sandbox' => 2], 'capacity');
        $second = $this->coordinator(['sandbox' => 2], 'capacity');

        try {
            $firstPermit = $first->tryAcquire(['sandbox']);
            $secondPermit = $second->tryAcquire(['sandbox']);

            expect($firstPermit)->toBeInstanceOf(MachineResourcePermit::class);
            expect($secondPermit)->toBeInstanceOf(MachineResourcePermit::class);
            expect($first->tryAcquire(['sandbox']))->toBe(false);

            $first->release($firstPermit);

            expect($first->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
        } finally {
            $first->close();
            $second->close();
        }
    }

    #[Test]
    public function partialAcquisitionIsReleasedWhenAnotherResourceIsBusy(): void
    {
        $first = $this->coordinator(['database' => 1, 'sandbox' => 1], 'atomic');
        $second = $this->coordinator(['database' => 1, 'sandbox' => 1], 'atomic');

        try {
            $sandbox = $first->tryAcquire(['sandbox']);
            expect($sandbox)->toBeInstanceOf(MachineResourcePermit::class);
            expect($second->tryAcquire(['database', 'sandbox']))->toBe(false);
            expect($second->tryAcquire(['database']))->toBeInstanceOf(MachineResourcePermit::class);
        } finally {
            $first->close();
            $second->close();
        }
    }

    #[Test]
    public function activeProcessesMustAgreeOnTheLimit(): void
    {
        $first = $this->coordinator(['sandbox' => 1], 'definition');

        try {
            expect()->calling(fn(): MachineResourceCoordinator => $this->coordinator(['sandbox' => 2], 'definition'))
                ->toThrow(ResourceCoordinationError::class, matching: '/active limit 1.+configured limit 2/');
        } finally {
            $first->close();
        }

        $replacement = $this->coordinator(['sandbox' => 2], 'definition');
        $replacement->close();
    }

    #[Test]
    public function namespacesDoNotContend(): void
    {
        $first = $this->coordinator(['sandbox' => 1], 'first');
        $second = $this->coordinator(['sandbox' => 1], 'second');

        try {
            expect($first->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
            expect($second->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
        } finally {
            $first->close();
            $second->close();
        }
    }

    #[Test]
    public function nestedRunsFailInsteadOfWaitingOnTheirOuterLease(): void
    {
        $outer = $this->coordinator(['sandbox' => 1], 'nested');
        $permit = $outer->tryAcquire(['sandbox']);
        expect($permit)->toBeInstanceOf(MachineResourcePermit::class);
        $inherited = MachineResourceEnvironment::inherited();

        try {
            MachineResourceEnvironment::set($permit->coordinationKeys);

            expect()->calling(fn(): MachineResourceCoordinator => $this->coordinator(['sandbox' => 1], 'nested'))
                ->toThrow(ResourceCoordinationError::class, matching: '/outer Greenlight run holds/');
        } finally {
            MachineResourceEnvironment::set($inherited);
            $outer->close();
        }
    }

    /**
     * @param array<non-empty-string, positive-int> $limits
     * @param non-empty-string $namespace
     */
    private function coordinator(array $limits, string $namespace): MachineResourceCoordinator
    {
        return MachineResourceCoordinator::open(
            $limits,
            $namespace,
            $this->tempDirectory->subdirectory('machine-resource-locks'),
        );
    }
}
