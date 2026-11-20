<?php

declare(strict_types=1);

namespace Constile\Gate\Http;

use Constile\Gate\GateRequest;
use Constile\Gate\GateResponse;
use Constile\Gate\Headers;

/**
 * HTTP through PHP's stream wrappers, for hosts without curl. Only http and
 * https URLs; no redirects. It asks for an unencoded body and decodes gzip
 * itself when a server sends it anyway.
 */
final class StreamClient implements HttpClient
{
    public function __construct(private bool $verifyTls = true)
    {
    }

    public function send(GateRequest $request, float $timeout): GateResponse
    {
        $scheme = strtolower((string) parse_url($request->url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new TransportException("Only http and https URLs can be fetched, not {$request->url}");
        }
        $lines = [];
        foreach ($request->headers as [$name, $value]) {
            if (in_array(strtolower($name), ['content-length', 'accept-encoding', 'connection', 'transfer-encoding'], true)) {
                continue;
            }
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = 'Accept-Encoding: identity';
        $lines[] = 'Connection: close';
        $http = [
            'method' => strtoupper($request->method),
            'header' => implode("\r\n", $lines),
            'timeout' => max(0.001, $timeout),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
            'protocol_version' => 1.1,
        ];
        if ($request->body !== null) {
            $http['content'] = $request->body;
        }
        $context = stream_context_create([
            'http' => $http,
            'ssl' => ['verify_peer' => $this->verifyTls, 'verify_peer_name' => $this->verifyTls],
        ]);
        $deadline = microtime(true) + $timeout;
        $stream = @fopen($request->url, 'rb', false, $context);
        if ($stream === false) {
            $error = error_get_last();
            throw new TransportException($error['message'] ?? "Couldn't reach {$request->url}");
        }
        $meta = stream_get_meta_data($stream);
        $raw = $meta['wrapper_data'] ?? [];
        $body = '';
        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                fclose($stream);
                throw new TransportException("Timed out reading {$request->url}");
            }
            stream_set_timeout($stream, (int) floor($remaining), (int) (($remaining - floor($remaining)) * 1e6));
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (stream_get_meta_data($stream)['timed_out']) {
                fclose($stream);
                throw new TransportException("Timed out reading {$request->url}");
            }
        }
        fclose($stream);

        $headers = new Headers();
        $status = 0;
        $reason = '';
        foreach (is_array($raw) ? $raw : [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s+(.*))?$/', $line, $m) === 1) {
                $headers = new Headers();
                $status = (int) $m[1];
                $reason = $m[2] ?? '';
            } elseif (($colon = strpos($line, ':')) !== false) {
                try {
                    $headers->add(trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1)));
                } catch (\InvalidArgumentException) {
                }
            }
        }
        $encoding = strtolower((string) $headers->get('content-encoding'));
        if ($encoding === 'gzip' || $encoding === 'x-gzip' || $encoding === 'deflate') {
            $decoded = function_exists('gzdecode') ? @gzdecode($body) : false;
            if ($decoded === false && $encoding === 'deflate' && function_exists('gzuncompress')) {
                $decoded = @gzuncompress($body);
                $decoded = $decoded === false ? @gzinflate($body) : $decoded;
            }
            if ($decoded === false) {
                throw new TransportException("Couldn't decode the {$encoding} body from {$request->url}");
            }
            $body = $decoded;
        } elseif ($encoding !== '' && $encoding !== 'identity') {
            throw new TransportException("Unsupported content encoding {$encoding} from {$request->url}");
        }
        foreach (['content-encoding', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'] as $h) {
            $headers->remove($h);
        }
        return new GateResponse($status, $headers, $body, null, $reason);
    }
}
