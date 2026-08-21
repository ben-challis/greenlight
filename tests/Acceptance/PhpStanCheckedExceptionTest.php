<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanCheckedExceptionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function controlSignalsNeedThrowsTagsOutsideTestCode(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Tests\Probe;

            use Greenlight\Core\Result\FailureDetail;
            use Greenlight\Expect\ExpectationFailed;

            function undocumentedTestHelper(): void
            {
                throw ExpectationFailed::fromDetail(new FailureDetail('Expected test failure.'));
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Probe;

            use Greenlight\Core\Result\FailureDetail;
            use Greenlight\Core\Wire\WireError;
            use Greenlight\Coverage\CoverageError;
            use Greenlight\Doubles\DoublesError;
            use Greenlight\Expect\ExpectationFailed;
            use Greenlight\Sandbox\TemporaryDirectoryError;
            use Greenlight\Harness\ServiceResolutionError;
            use Greenlight\Harness\UnresolvableService;
            use Greenlight\Reporting\ReportingError;
            use Greenlight\Runner\Integration\IntegrationFixtureError;
            use Greenlight\Runner\Protocol\ProtocolError;
            use Greenlight\Runner\Worker\WorkerError;

            final class ProbeWireError extends WireError {}
            final class ProbeServiceResolutionError extends ServiceResolutionError {}

            function undocumentedProductionHelper(): void
            {
                throw ExpectationFailed::fromDetail(new FailureDetail('Expected production failure.'));
            }

            function undocumentedOperationalError(): void
            {
                throw CoverageError::driverUnavailable('probe', 'Expected operational failure.');
            }

            function undocumentedAuthoringError(): void
            {
                throw DoublesError::invalidTimes(-1);
            }

            function undocumentedResolutionError(): void
            {
                throw UnresolvableService::unknownType(\stdClass::class, \stdClass::class);
            }

            function undocumentedReportingError(): void
            {
                throw ReportingError::writeFailed();
            }

            function undocumentedIntegrationError(): void
            {
                throw IntegrationFixtureError::cleanup([]);
            }

            function undocumentedTemporaryDirectoryError(): void
            {
                throw TemporaryDirectoryError::symbolicLink('/probe');
            }

            function undocumentedProtocolError(): void
            {
                throw ProtocolError::malformedFrame('probe');
            }

            function undocumentedWireErrorSubtype(): void
            {
                throw new ProbeWireError('Expected wire failure.');
            }

            function undocumentedServiceResolutionErrorSubtype(): void
            {
                throw new ProbeServiceResolutionError('Expected service resolution failure.');
            }

            function undocumentedWorkerError(): void
            {
                throw WorkerError::conditionClassMissing(\stdClass::class);
            }
            PHP,
            \dirname(__DIR__, 2) . '/phpstan.dist.neon',
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan MUST reject an undocumented production control signal')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan MUST not require throws tags in test code')
            ->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(11);
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Expect\\ExpectationFailed but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Coverage\\CoverageError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Doubles\\DoublesError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Harness\\UnresolvableService but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Reporting\\ReportingError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Runner\\Integration\\IntegrationFixtureError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Sandbox\\TemporaryDirectoryError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Runner\\Protocol\\ProtocolError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Probe\\ProbeWireError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Probe\\ProbeServiceResolutionError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Runner\\Worker\\WorkerError but it\'s missing from the PHPDoc @throws tag.',
        );
    }

    #[Test]
    public function throwsTagsMustBeCovariant(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Probe\Covariant;

            use Greenlight\Coverage\CoverageError;

            interface ParentContract
            {
                /** @throws CoverageError */
                public function run(): void;
            }

            final class GoodChild implements ParentContract
            {
                /** @throws CoverageError */
                public function run(): void
                {
                    throw CoverageError::driverUnavailable('probe', 'Expected operational failure.');
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Probe\NotCovariant;

            use Greenlight\Core\Result\FailureDetail;
            use Greenlight\Coverage\CoverageError;
            use Greenlight\Expect\ExpectationFailed;

            interface ParentContract
            {
                /** @throws ExpectationFailed */
                public function run(): void;
            }

            final class BadChild implements ParentContract
            {
                /** @throws CoverageError */
                public function run(): void
                {
                    throw CoverageError::driverUnavailable('probe', 'Expected operational failure.');
                }
            }
            PHP,
            \dirname(__DIR__, 2) . '/phpstan.dist.neon',
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan MUST reject a wider implementation throws contract')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan MUST accept a covariant implementation throws contract')
            ->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(1);
        Expect::that($probe->messages())->toContain('should be covariant with PHPDoc @throws type');
    }

    #[Test]
    public function throwsTagsMustNotBeWiderThanTheImplementation(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Probe\ExactThrows;

            use Greenlight\Coverage\CoverageError;

            /** @throws CoverageError */
            function documentedFailure(): void
            {
                throw CoverageError::driverUnavailable('probe', 'Expected operational failure.');
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Greenlight\Probe\WideThrows;

            use Greenlight\Coverage\CoverageError;

            /** @throws CoverageError */
            function documentedFailure(): void {}
            PHP,
            \dirname(__DIR__, 2) . '/phpstan.dist.neon',
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan MUST reject a throws type that the implementation does not throw')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan MUST accept an exact throws contract')
            ->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(1);
        Expect::that($probe->messages())->toContain(
            'has Greenlight\\Coverage\\CoverageError in PHPDoc @throws tag but it\'s not thrown.',
        );
    }
}
