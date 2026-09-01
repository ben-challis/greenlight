<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanTimeoutConstructorRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function timeoutConstructionRequiresFinitePositiveSeconds(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\Timeout;

            new Timeout(0.5);
            new Timeout(seconds: 1.0);
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Attribute\Timeout;

            new Timeout(0.0);
            new Timeout(-1.5);
            new Timeout(\NAN);
            new Timeout(\INF);
            new Timeout(seconds: 0.0);
            PHP,
        );

        Expect::that($probe->exitCode)->because('timeout construction requires valid seconds')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that($probe->errors)->toHaveCount(5);
        Expect::that($probe->messages())
            ->because('each invalid timeout value has the same actionable diagnostic')
            ->toBe(\implode("\n", \array_fill(0, 5, 'Timeout seconds must be finite and greater than zero.')));
    }
}
