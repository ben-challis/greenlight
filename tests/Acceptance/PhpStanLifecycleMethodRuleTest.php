<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanLifecycleMethodRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function lifecycleHooksMustBeRunnableWithoutArguments(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightLifecycleMethodProbe;

            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Before;

            final class GoodLifecycleMethodProbe
            {
                #[Before]
                public function before(): void {}

                #[After]
                public function after(int $optional = 1): void
                {
                    echo $optional;
                }

                #[Before]
                public function variadicBefore(string ...$values): void
                {
                    echo \implode('', $values);
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightLifecycleMethodProbe;

            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Before;

            final class BadLifecycleMethodProbe
            {
                #[Before]
                protected function protectedBefore(): void {}

                #[After]
                public static function staticAfter(): void {}

                #[Before]
                public function beforeWithArgument(int $value): void
                {
                    echo $value;
                }
            }

            abstract class AbstractLifecycleMethodProbe
            {
                #[After]
                abstract public function abstractAfter(): void;
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('lifecycle hooks must run without arguments')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(4)
            ->and($probe->messages())->toContain('protectedBefore() cannot run because it is not public')
            ->toContain('staticAfter() cannot run because it is static')
            ->toContain('beforeWithArgument() must not require arguments')
            ->toContain('abstractAfter() cannot run because it is abstract');
    }
}
