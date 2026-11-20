<?php

declare(strict_types=1);

namespace Constile\Gate\Symfony;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The gate as a Symfony HttpKernel decorator (a StackPHP-style middleware),
 * as Drupal's http_middleware services are. It gates main requests only.
 */
final class GateMiddleware implements HttpKernelInterface
{
    /** @param Adapter|\Closure(): ?Adapter $adapter An adapter, or a closure that makes one per request (null when the gate isn't set up). */
    public function __construct(private HttpKernelInterface $app, private Adapter|\Closure $adapter)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $adapter = $this->adapter instanceof \Closure ? ($this->adapter)() : $this->adapter;
        if ($type !== self::MAIN_REQUEST || $adapter === null) {
            return $this->app->handle($request, $type, $catch);
        }
        return $adapter->handle($request, fn (Request $r): Response => $this->app->handle($r, $type, $catch));
    }
}
