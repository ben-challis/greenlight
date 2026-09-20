<?php

declare(strict_types=1);

namespace Greenlight\Psr15;

use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Supplies one HTTP harness in the configured service scope.
 * Each harness calls its handler factory on the first request.
 *
 * For a new application handler in each test attempt, use the per-test scope.
 * Supply a factory that creates a new handler each time.
 */
final readonly class Psr15Plugin implements HarnessProvider
{
    /** @var \Closure(): RequestHandlerInterface */
    private \Closure $factory;

    /**
     * @param RequestHandlerInterface|\Closure(): RequestHandlerInterface $handler
     *   A handler or a factory that returns a handler.
     * @param null|\Closure(RequestHandlerInterface): void $release
     *   A callback that releases the active handler when its scope closes.
     */
    public function __construct(
        RequestHandlerInterface|\Closure $handler,
        private Scope $scope = Scope::PerTest,
        private ?\Closure $release = null,
    ) {
        $this->factory = $handler instanceof RequestHandlerInterface
            ? static fn(): RequestHandlerInterface => $handler
            : $handler;
    }

    /**
     * @return list<ServiceDefinition>
     */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(HttpHarness::class, $this->scope, $this->harness(...)),
        ];
    }

    private function harness(): HttpHarness
    {
        return new HttpHarness($this->factory, $this->release);
    }
}
