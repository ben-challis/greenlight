<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Attempt;

use App\DisposalProbe;
use App\Greeter;
use App\VisitCounter;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;

final readonly class HyperfAttemptLifetimeTest
{
    public function __construct(
        private Greeter $greeter,
        private VisitCounter $counter,
        private DisposalProbe $probe,
    ) {}

    #[Test]
    public function firstAttemptUsesAnIsolatedContainer(): void
    {
        Expect::that(Coroutine::inCoroutine())->toBeTrue();
        Expect::that($this->greeter->greet('Ada'))->toBe('Hello, Ada through AOP');
        Expect::that($this->counter->count())->toBe(0);
        Expect::that($this->probe->snapshot())->toBe([
            'containers' => 1,
            'resets' => 0,
            'disposals' => 0,
            'resetInCoroutine' => false,
            'disposeInCoroutine' => false,
        ]);

        $this->counter->record();
        Context::set('greenlight.hyperf.probe', 'first attempt');
    }

    #[Test]
    public function nextAttemptGetsANewContainerAndCoroutineContext(): void
    {
        Expect::that($this->counter->count())->toBe(0);
        Expect::that(Context::has('greenlight.hyperf.probe'))->toBeFalse();
        Expect::that($this->probe->snapshot())->toBe([
            'containers' => 2,
            'resets' => 1,
            'disposals' => 1,
            'resetInCoroutine' => true,
            'disposeInCoroutine' => true,
        ]);
    }
}
