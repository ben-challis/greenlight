<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Worker;

use App\DisposalProbe;
use App\Greeter;
use App\NamedGreeter;
use App\VisitCounter;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\Service;
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
        Expect::that($this->application)->toBeInstanceOf(Application::class);
        Expect::that(Coroutine::inCoroutine())->toBeTrue();
        Expect::that($this->greeter->greet('Ada'))->toBe('Hello, Ada through AOP');
        Expect::that($this->namedGreeter->greet())->toBe('Named service');
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
    public function nextAttemptKeepsWorkerStateButReplacesCoroutineContext(): void
    {
        Expect::that($this->counter->count())->toBe(1);
        Expect::that(Context::has('greenlight.hyperf.probe'))->toBeFalse();
        Expect::that($this->probe->snapshot())->toBe([
            'containers' => 1,
            'resets' => 1,
            'disposals' => 0,
            'resetInCoroutine' => true,
            'disposeInCoroutine' => false,
        ]);
    }
}
