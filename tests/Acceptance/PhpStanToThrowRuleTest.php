<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Runs the real PHPStan binary with the shipped extension against probe code
 * calling toThrow(): class-only, pattern-only, and message-only calls must
 * analyse clean, while combining pattern and exact-message constraints must
 * be flagged.
 */
final class PhpStanToThrowRuleTest
{
    #[Test]
    public function patternAndExactMessageConstraintsAreMutuallyExclusive(): void
    {
        $root = \dirname(__DIR__, 2);
        $probeDir = \sys_get_temp_dir() . '/greenlight-phpstan-to-throw-probe-' . \bin2hex(\random_bytes(4));
        \mkdir($probeDir, 0o777, true);

        $good = $probeDir . '/GoodToThrowProbe.php';
        $bad = $probeDir . '/BadToThrowProbe.php';

        \file_put_contents($good, <<<'PHP'
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
            }
            PHP);

        \file_put_contents($bad, <<<'PHP'
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
            }
            PHP);

        try {
            $command = \sprintf(
                'cd %s && php vendor/bin/phpstan analyse --no-progress --error-format=json -c tests/Fixture/PhpStanExtension/probe.neon %s %s 2>/dev/null',
                \escapeshellarg($root),
                \escapeshellarg($good),
                \escapeshellarg($bad),
            );

            \exec($command, $output, $exitCode);
            $report = \json_decode(\implode('', $output), true);
        } finally {
            @\unlink($good);
            @\unlink($bad);
            @\rmdir($probeDir);
        }

        Expect::that(\is_array($report))->toBeTrue();
        \assert(\is_array($report) && \is_array($report['files']));

        $badFile = $report['files'][$bad] ?? [];
        \assert(\is_array($badFile));
        $badErrors = \is_array($badFile['messages'] ?? null) ? $badFile['messages'] : [];
        $messages = \implode("\n", \array_filter(\array_column($badErrors, 'message'), \is_string(...)));

        Expect::that($exitCode)->toBe(1)
            ->and(isset($report['files'][$good]))->toBeFalse()
            ->and(\count($badErrors))->toBe(4)
            ->and($messages)->toContain('toThrow() accepts either matching: or message:, not both');
    }
}
