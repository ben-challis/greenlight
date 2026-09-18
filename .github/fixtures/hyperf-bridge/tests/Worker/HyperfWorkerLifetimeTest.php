<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Worker;

use App\DisposalProbe;
use App\Greeter;
use App\NamedGreeter;
use App\VisitCounter;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Service;
use Hyperf\Context\Context;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Coroutine\Coroutine;
use Symfony\Component\Console\Application;

final readonly class HyperfWorkerLifetimeTest
{
    public function __construct(
        #[Service(ApplicationInterface::class)] private Application $application,
        private Greeter $greeter,
        private VisitCounter $counter,
        private DisposalProbe $probe,
        #[Service('probe.named_greeter')] private NamedGreeter $namedGreeter,
    ) {}

    #[Test]
    public function firstAttemptUsesTheBootedWorkerContainer(): void
    {
        Expect::value($this->application)->toBeInstanceOf(Application::class);
        Expect::value(Coroutine::inCoroutine())->toBeTrue();
        Expect::value($this->greeter->greet('Ada'))->toBe('Hello, Ada through AOP');
        Expect::value($this->namedGreeter->greet())->toBe('Named service');
        Expect::value($this->counter->count())->toBe(0);
        Expect::value($this->probe->snapshot())->toBe([
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
        Expect::value($this->counter->count())->toBe(1);
        Expect::value(Context::has('greenlight.hyperf.probe'))->toBeFalse();
        Expect::value($this->probe->snapshot())->toBe([
            'containers' => 1,
            'resets' => 1,
            'disposals' => 0,
            'resetInCoroutine' => true,
            'disposeInCoroutine' => false,
        ]);
    }
}
