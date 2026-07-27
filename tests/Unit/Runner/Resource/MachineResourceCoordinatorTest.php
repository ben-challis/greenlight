<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Resource;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Resource\MachineResourceCoordinator;
use Greenlight\Runner\Resource\MachineResourceEnvironment;
use Greenlight\Runner\Resource\MachineResourcePermit;
use Greenlight\Runner\Resource\ResourceCoordinationError;

final readonly class MachineResourceCoordinatorTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function processesShareTheConfiguredCapacity(): void
    {
        $first = $this->coordinator(['sandbox' => 2], 'capacity');
        $second = $this->coordinator(['sandbox' => 2], 'capacity');

        try {
            $firstPermit = $first->tryAcquire(['sandbox']);
            $secondPermit = $second->tryAcquire(['sandbox']);

            Expect::that($firstPermit)->toBeInstanceOf(MachineResourcePermit::class);
            Expect::that($secondPermit)->toBeInstanceOf(MachineResourcePermit::class);
            Expect::that($first->tryAcquire(['sandbox']))->toBe(false);

            if ($firstPermit instanceof MachineResourcePermit) {
                $first->release($firstPermit);
            }

            Expect::that($first->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
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
            Expect::that($sandbox)->toBeInstanceOf(MachineResourcePermit::class);
            Expect::that($second->tryAcquire(['database', 'sandbox']))->toBe(false);
            Expect::that($second->tryAcquire(['database']))->toBeInstanceOf(MachineResourcePermit::class);
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
            Expect::that(fn(): MachineResourceCoordinator => $this->coordinator(['sandbox' => 2], 'definition'))
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
            Expect::that($first->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
            Expect::that($second->tryAcquire(['sandbox']))->toBeInstanceOf(MachineResourcePermit::class);
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
        Expect::that($permit)->toBeInstanceOf(MachineResourcePermit::class);
        $inherited = MachineResourceEnvironment::inherited();

        try {
            if ($permit instanceof MachineResourcePermit) {
                MachineResourceEnvironment::set($permit->coordinationKeys);
            }

            Expect::that(fn(): MachineResourceCoordinator => $this->coordinator(['sandbox' => 1], 'nested'))
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
