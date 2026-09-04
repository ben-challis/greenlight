<?php

declare(strict_types=1);

namespace Greenlight\Psr15;

use Greenlight\Harness\Disposable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends PSR-7 server requests directly to one PSR-15 request handler.
 * If a factory supplies the handler, the first request creates it.
 *
 * Disposal calls the optional release callback only if a handler exists.
 */
final class HttpHarness implements Disposable
{
    /** @var null|\Closure(): RequestHandlerInterface */
    private readonly ?\Closure $factory;

    private ?RequestHandlerInterface $handler = null;

    private bool $disposed = false;

    /**
     * @param RequestHandlerInterface|\Closure(): RequestHandlerInterface $handler
     * @param null|\Closure(RequestHandlerInterface): void $release
     */
    public function __construct(
        RequestHandlerInterface|\Closure $handler,
        private readonly ?\Closure $release = null,
    ) {
        if ($handler instanceof RequestHandlerInterface) {
            $this->handler = $handler;
            $this->factory = null;

            return;
        }

        $this->factory = $handler;
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
                $handler,
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
            throw Psr15Error::releaseFailed($handler, $threw);
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

        $factory = $this->factory;
        \assert($factory instanceof \Closure);

        try {
            $handler = $factory();
        } catch (\Throwable $threw) {
            throw Psr15Error::factoryFailed($threw);
        }

        if (!$handler instanceof RequestHandlerInterface) {
            throw Psr15Error::invalidHandler($handler);
        }

        $this->handler = $handler;

        return $handler;
    }
}
