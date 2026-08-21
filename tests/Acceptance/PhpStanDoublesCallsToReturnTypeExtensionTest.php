<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDoublesCallsToReturnTypeExtensionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function callsToReturnsTheSelectedMethodsArgumentTypes(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface TypedCallSpy
            {
                public function notify(string $message, int $priority = 0): void;

                public function tag(string $name, int ...$values): void;
            }

            /** @param list<array{0: string, 1?: int}> $calls */
            function acceptNotifyCalls(array $calls): void {}

            /** @param list<non-empty-list<int|string>> $calls */
            function acceptTagCalls(array $calls): void {}

            /** @param list<list<mixed>> $calls */
            function acceptDynamicCalls(array $calls): void {}

            function greenlightTypedCallProbe(Doubles $doubles, string $dynamicMethod): void
            {
                $spy = $doubles->spy(TypedCallSpy::class);

                acceptNotifyCalls($doubles->callsTo($spy, 'notify'));
                acceptTagCalls($doubles->callsTo(method: 'tag', double: $spy));
                acceptDynamicCalls($doubles->callsTo($spy, $dynamicMethod));
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface MistypedCallSpy
            {
                public function notify(string $message): void;
            }

            /** @param list<array{int}> $calls */
            function acceptMistypedCalls(array $calls): void {}

            function greenlightMistypedCallProbe(Doubles $doubles): void
            {
                $spy = $doubles->spy(MistypedCallSpy::class);

                acceptMistypedCalls($doubles->callsTo($spy, 'notify'));
            }
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan rejects an incompatible asserted callsTo() result type')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())->toContain('expects list<array{int}>, list<array{string}> given');
    }
}
