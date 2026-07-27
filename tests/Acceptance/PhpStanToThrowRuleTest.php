<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

final readonly class PhpStanToThrowRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function patternAndExactMessageConstraintsAreMutuallyExclusive(): void
    {
        $probe = PhpStanProbe::analyse(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodToThrowProbe(): void
            {
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class);
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, message: 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, null, 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, null, 'boom']);
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(DomainException::class, message: 'boom');
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadToThrowProbe(): void
            {
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(DomainException::class, '/boom/', 'boom');
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[
                        'throwable' => DomainException::class,
                        'matching' => '/boom/',
                        'message' => 'boom',
                    ]);
                Expect::that(static fn() => throw new DomainException('boom'))
                    ->toThrow(...[DomainException::class, '/boom/', 'boom']);
                Expect::eventually(static fn(): Closure => static fn() => throw new DomainException('boom'))
                    ->within(1.0)
                    ->toThrow(DomainException::class, matching: '/boom/', message: 'boom');
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('pattern and exact message constraints are mutually exclusive')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(5)
            ->and($probe->messages())->toContain('toThrow() accepts either matching: or message:, not both');
    }
}
