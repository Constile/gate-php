<?php

declare(strict_types=1);

namespace Constile\Gate\Http;

use Constile\Gate\GateRequest;
use Constile\Gate\GateResponse;
use Constile\Gate\Headers;

/** HTTP through the curl extension. Only http and https URLs; no redirects. */
final class CurlClient implements HttpClient
{
    public function __construct(private bool $verifyTls = true)
    {
    }

    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    public function send(GateRequest $request, float $timeout): GateResponse
    {
        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new TransportException("Only http and https URLs can be fetched, not {$request->url}");
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $lines = [];
        foreach ($request->headers as [$name, $value]) {
            // curl computes these itself; Accept-Encoding comes from CURLOPT_ENCODING.
            if (in_array(strtolower($name), ['content-length', 'accept-encoding', 'connection', 'transfer-encoding'], true)) {
                continue;
            }
            $lines[] = "{$name}: {$value}";
        }
        // Without this, curl sends "Expect: 100-continue" on larger bodies and waits for it.
        $lines[] = 'Expect:';
        $headers = new Headers();
        $status = 0;
        $reason = '';
        $method = strtoupper($request->method);
        $ms = max(1, (int) round($timeout * 1000));
        $opts = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_CONNECTTIMEOUT_MS => $ms,
            CURLOPT_NOSIGNAL => true,
            // Ask for any encoding curl can decode, and get the body decoded.
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($headers, &$status, &$reason): int {
                $trimmed = rtrim($line, "\r\n");
                if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s+(.*))?$/', $trimmed, $m) === 1) {
                    // A new status line (after 100 Continue, for example) starts a new header block.
                    foreach ($headers->all() as [$n]) {
                        $headers->remove($n);
                    }
                    $status = (int) $m[1];
                    $reason = $m[2] ?? '';
                } elseif (($colon = strpos($trimmed, ':')) !== false) {
                    try {
                        $headers->add(trim(substr($trimmed, 0, $colon)), trim(substr($trimmed, $colon + 1)));
                    } catch (\InvalidArgumentException) {
                        // Skip a malformed header rather than fail the response.
                    }
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } elseif ($request->body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportException($error !== '' ? $error : 'curl request failed');
        }
        curl_close($ch);
        foreach (['content-encoding', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'] as $h) {
            $headers->remove($h);
        }
        return new GateResponse($status, $headers, (string) $body, null, $reason);
    }
}
