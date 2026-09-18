<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Attempt;

use App\DisposalProbe;
use App\Greeter;
use App\VisitCounter;
use Greenlight\Attribute\Test;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;

use function Greenlight\expect;

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
        expect(Coroutine::inCoroutine())->toBeTrue();
        expect($this->greeter->greet('Ada'))->toBe('Hello, Ada through AOP');
        expect($this->counter->count())->toBe(0);
        expect($this->probe->snapshot())->toBe([
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
        expect($this->counter->count())->toBe(0);
        expect(Context::has('greenlight.hyperf.probe'))->toBeFalse();
        expect($this->probe->snapshot())->toBe([
            'containers' => 2,
            'resets' => 1,
            'disposals' => 1,
            'resetInCoroutine' => true,
            'disposeInCoroutine' => true,
        ]);
    }
}
