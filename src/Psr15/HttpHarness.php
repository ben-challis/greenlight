<?php

declare(strict_types=1);

namespace Greenlight\Psr15;

use Greenlight\Harness\Disposable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends PSR-7 server requests directly to one PSR-15 request handler.
 * The optional release callback closes handler state when the harness scope closes.
 */
final class HttpHarness implements Disposable
{
    /** @var \Closure(): mixed */
    private readonly \Closure $factory;

    private ?RequestHandlerInterface $handler = null;

    private bool $disposed = false;

    /**
     * @param RequestHandlerInterface|\Closure(): mixed $handler
     * @param null|\Closure(RequestHandlerInterface): void $release
     */
    public function __construct(
        RequestHandlerInterface|\Closure $handler,
        private readonly ?\Closure $release = null,
    ) {
        $this->factory = $handler instanceof RequestHandlerInterface
            ? static fn(): RequestHandlerInterface => $handler
            : $handler;
    }

    /**
     * @throws Psr15Error
     */
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->disposed) {
            throw Psr15Error::disposed();
        }

        $handler = $this->handler();
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        try {
            return $handler->handle($request);
        } catch (\Throwable $threw) {
            throw Psr15Error::requestFailed(
                $method === '' ? '<empty>' : $method,
                $path === '' ? '/' : $path,
                \get_debug_type($handler),
                $threw,
            );
        }
    }

    /**
     * @throws Psr15Error
     */
    #[\Override]
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $handler = $this->handler;
        $this->handler = null;

        if (!$handler instanceof RequestHandlerInterface || !$this->release instanceof \Closure) {
            return;
        }

        try {
            ($this->release)($handler);
        } catch (\Throwable $threw) {
            throw Psr15Error::releaseFailed(\get_debug_type($handler), $threw);
        }
    }

    /**
     * @throws Psr15Error
     */
    private function handler(): RequestHandlerInterface
    {
        if ($this->handler instanceof RequestHandlerInterface) {
            return $this->handler;
        }

        try {
            $handler = ($this->factory)();
        } catch (\Throwable $threw) {
            throw Psr15Error::factoryFailed($threw);
        }

        if (!$handler instanceof RequestHandlerInterface) {
            throw Psr15Error::invalidHandler(\get_debug_type($handler));
        }

        $this->handler = $handler;

        return $handler;
    }
}
