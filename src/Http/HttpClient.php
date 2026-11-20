<?php

declare(strict_types=1);

namespace Constile\Gate\Http;

use Constile\Gate\GateRequest;
use Constile\Gate\GateResponse;

/**
 * Sends one HTTP request. Implementations don't follow redirects, return the
 * body decoded (no Content-Encoding), and throw TransportException when the
 * server can't be reached or doesn't answer within the timeout.
 */
interface HttpClient
{
    /** @param float $timeout Seconds for the whole exchange, connection included. */
    public function send(GateRequest $request, float $timeout): GateResponse;
}
