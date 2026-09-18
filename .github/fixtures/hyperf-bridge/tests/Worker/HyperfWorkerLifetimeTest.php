<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Worker;

use App\DisposalProbe;
use App\Greeter;
use App\NamedGreeter;
use App\VisitCounter;
use Greenlight\Attribute\Test;
use Greenlight\Harness\Service;
use Hyperf\Context\Context;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Coroutine\Coroutine;
use Symfony\Component\Console\Application;

use function Greenlight\expect;

final readonly class HyperfWorkerLifetimeTest
{
    public function __construct(
        #[Service(ApplicationInterface::class)]
        private Application $application,
        private Greeter $greeter,
        private VisitCounter $counter,
        private DisposalProbe $probe,
        #[Service('probe.named_greeter')]
        private NamedGreeter $namedGreeter,
    ) {}

    #[Test]
    public function firstAttemptUsesTheBootedWorkerContainer(): void
    {
        expect($this->application)->toBeInstanceOf(Application::class);
        expect(Coroutine::inCoroutine())->toBeTrue();
        expect($this->greeter->greet('Ada'))->toBe('Hello, Ada through AOP');
        expect($this->namedGreeter->greet())->toBe('Named service');
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
    public function nextAttemptKeepsWorkerStateButReplacesCoroutineContext(): void
    {
        expect($this->counter->count())->toBe(1);
        expect(Context::has('greenlight.hyperf.probe'))->toBeFalse();
        expect($this->probe->snapshot())->toBe([
            'containers' => 1,
            'resets' => 1,
            'disposals' => 0,
            'resetInCoroutine' => true,
            'disposeInCoroutine' => false,
        ]);
    }
}
