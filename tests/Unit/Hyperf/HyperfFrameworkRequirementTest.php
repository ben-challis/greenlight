<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\HyperfBridgeError;
use Greenlight\Hyperf\HyperfFrameworkRequirement;

final readonly class HyperfFrameworkRequirementTest
{
    #[Test]
    public function acceptsACompleteRuntimeEnvironment(): void
    {
        HyperfFrameworkRequirement::checkEnvironment(true, '3.2.0', '5.1.0', true);

        Expect::that(true)->toBeTrue();
    }

    #[Test]
    public function rejectsAnUnavailableFrameworkEnvironment(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkEnvironment(false, '3.2.0', '5.1.0', true);
        })->toThrow(HyperfBridgeError::class, matching: '/requires hyperf\/framework and hyperf\/di 3\.2/');
    }

    #[Test]
    public function rejectsAnUnavailablePcntlExtension(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkEnvironment(true, '3.2.0', '5.1.0', false);
        })->toThrow(HyperfBridgeError::class, matching: '/requires the pcntl extension/');
    }

    #[Test]
    public function acceptsHyperfThreePointTwoVersions(): void
    {
        HyperfFrameworkRequirement::checkFrameworkVersion('v3.2.0');

        Expect::that(true)->toBeTrue();
    }

    #[Test]
    public function rejectsOtherHyperfVersions(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkFrameworkVersion('3.1.70');
        })->toThrow(HyperfBridgeError::class, matching: '/requires version 3\.2/');
    }

    #[Test]
    public function rejectsAnUnavailableHyperfFramework(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkFrameworkVersion(null);
        })->toThrow(HyperfBridgeError::class, matching: '/requires hyperf\/framework and hyperf\/di 3\.2/');
    }

    #[Test]
    public function acceptsSupportedSwooleVersions(): void
    {
        HyperfFrameworkRequirement::checkSwooleVersion('5.1.0');
        HyperfFrameworkRequirement::checkSwooleVersion('6.0.2');

        Expect::that(true)->toBeTrue();
    }

    #[Test]
    public function rejectsOldSwooleVersions(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkSwooleVersion('4.8.13');
        })->toThrow(HyperfBridgeError::class, matching: '/requires major version 5 or later/');
    }

    #[Test]
    public function rejectsAnUnavailableSwooleExtension(): void
    {
        Expect::that(static function (): void {
            HyperfFrameworkRequirement::checkSwooleVersion(false);
        })->toThrow(HyperfBridgeError::class, matching: '/requires the Swoole extension/');
    }
}
