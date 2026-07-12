<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Runs the real PHPStan binary with the shipped extension against probe code
 * calling the fixture matchers: correct calls must analyse clean and a wrong
 * argument type must be flagged, proving the reflected signatures are
 * enforced rather than swallowed by the __call fallback.
 */
final class PhpStanExtensionTest
{
    #[Test]
    public function reflectedMatcherSignaturesAreEnforced(): void
    {
        $root = \dirname(__DIR__, 2);
        $probeDir = \sys_get_temp_dir() . '/greenlight-phpstan-probe-' . \bin2hex(\random_bytes(4));
        \mkdir($probeDir, 0o777, true);

        $good = $probeDir . '/GoodProbe.php';
        $bad = $probeDir . '/BadProbe.php';

        \file_put_contents($good, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodProbe(): void
            {
                Expect::that('c0ffee')->toBeHexadecimal()
                    ->and('c0ffee')->toHaveDigestLength(6);
            }
            PHP);

        \file_put_contents($bad, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadProbe(): void
            {
                Expect::that('c0ffee')->toHaveDigestLength('six')
                    ->and('c0ffee')->toBeHexadecimal(123);
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
            ->and(\count($badErrors))->toBe(2)
            ->and($messages)->toContain('toHaveDigestLength() expects int, string given')
            ->and($messages)->toContain('invoked with 1 parameter, 0 required');
    }
}
