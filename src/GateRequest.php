<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * A request as the gate sees it. Adapters build one from their host's request
 * and may keep the host's own object in $native.
 */
final class GateRequest
{
    public Headers $headers;

    public function __construct(
        public string $method,
        public string $url,
        ?Headers $headers = null,
        public ?string $body = null,
        public mixed $native = null,
    ) {
        $this->headers = $headers ?? new Headers();
    }
}
