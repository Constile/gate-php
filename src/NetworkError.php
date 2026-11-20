<?php

declare(strict_types=1);

namespace Constile\Gate;

/** The network answered with an error status. The gate passes its status and message on for callbacks. */
final class NetworkError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
