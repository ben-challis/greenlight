<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanCheckedExceptionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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
            use Greenlight\Coverage\CoverageError;
            use Greenlight\Doubles\DoublesError;
            use Greenlight\Expect\ExpectationFailed;

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
            PHP,
            \dirname(__DIR__, 2) . '/phpstan.dist.neon',
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan MUST reject an undocumented production control signal')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan MUST not require throws tags in test code')
            ->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(3);
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Expect\\ExpectationFailed but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Coverage\\CoverageError but it\'s missing from the PHPDoc @throws tag.',
        );
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Doubles\\DoublesError but it\'s missing from the PHPDoc @throws tag.',
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
}
