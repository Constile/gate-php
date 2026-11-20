<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * A response from the gate, the origin, or the network. The body can be a
 * string or a closure that produces it once, when first read. Adapters may
 * keep the host's own response object in $native: when the gate passes an
 * origin response through unchanged, it returns the same object, so the
 * adapter can send the native response untouched.
 */
final class GateResponse
{
    public Headers $headers;

    /** @var string|\Closure(): string */
    private string|\Closure $body;

    private bool $forward = false;

    /**
     * @param string|\Closure(): string $body
     */
    public function __construct(
        public int $status = 200,
        ?Headers $headers = null,
        string|\Closure $body = '',
        public mixed $native = null,
        public string $reason = '',
    ) {
        $this->headers = $headers ?? new Headers();
        $this->body = $body;
    }

    /**
     * Tells an adapter to let its app handle the incoming request as if the
     * gate weren't there (WordPress continues loading, for example).
     */
    public static function forward(): self
    {
        $r = new self(0);
        $r->forward = true;
        return $r;
    }

    public function isForward(): bool
    {
        return $this->forward;
    }

    public function body(): string
    {
        if ($this->body instanceof \Closure) {
            $this->body = (string) ($this->body)();
        }
        return $this->body;
    }
}
