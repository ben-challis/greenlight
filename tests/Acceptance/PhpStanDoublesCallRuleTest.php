<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDoublesCallRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function callsToRequiresAMethodOnTheDoubledType(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface GoodSpyNotifier
            {
                public function notify(string $message): void;
            }

            function greenlightGoodSpyCallProbe(Doubles $doubles, string $dynamicMethod): void
            {
                $spy = $doubles->spy(GoodSpyNotifier::class);

                $doubles->callsTo($spy, 'notify');
                $doubles->callsTo(method: 'NOTIFY', double: $spy);
                $doubles->callsTo($spy, $dynamicMethod);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface BadSpyNotifier
            {
                public function notify(string $message): void;
            }

            function greenlightBadSpyCallProbe(Doubles $doubles): void
            {
                $spy = $doubles->spy(BadSpyNotifier::class);

                $doubles->callsTo($spy, 'notifiy');
                $doubles->callsTo(method: 'flush', double: $spy);
            }
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan rejects method names that the doubled type does not contain')
            ->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(2);
        Expect::that($probe->messages())
            ->toContain('callsTo() cannot inspect "notifiy()" on doubled type "BadSpyNotifier"');
        Expect::that($probe->messages())
            ->toContain('callsTo() cannot inspect "flush()" on doubled type "BadSpyNotifier"');
    }
}
