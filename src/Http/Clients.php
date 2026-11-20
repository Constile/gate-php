<?php

declare(strict_types=1);

namespace Constile\Gate\Http;

final class Clients
{
    /** curl when the extension is loaded, else PHP streams. */
    public static function auto(bool $verifyTls = true): HttpClient
    {
        return CurlClient::available() ? new CurlClient($verifyTls) : new StreamClient($verifyTls);
    }
}
