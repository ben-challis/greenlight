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
            use Greenlight\Expect\ExpectationFailed;

            function undocumentedProductionHelper(): void
            {
                throw ExpectationFailed::fromDetail(new FailureDetail('Expected production failure.'));
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
        Expect::that($probe->errors)->toHaveCount(1);
        Expect::that($probe->messages())->toContain(
            'throws checked exception Greenlight\\Expect\\ExpectationFailed but it\'s missing from the PHPDoc @throws tag.',
        );
    }
}
