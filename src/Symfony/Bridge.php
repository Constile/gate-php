<?php

declare(strict_types=1);

namespace Constile\Gate\Symfony;

use Constile\Gate\GateRequest;
use Constile\Gate\GateResponse;
use Constile\Gate\Headers;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Converts between Symfony HttpFoundation (which Laravel and Drupal use) and the gate's request and response. */
final class Bridge
{
    public static function request(Request $request): GateRequest
    {
        $headers = new Headers();
        foreach ($request->headers->all() as $name => $values) {
            foreach ((array) $values as $v) {
                try {
                    $headers->add((string) $name, (string) $v);
                } catch (\InvalidArgumentException) {
                }
            }
        }
        // getUri() uses the Host header (and the trusted-proxy settings): the gate ignores requests for hosts that aren't the site's.
        return new GateRequest($request->getMethod(), $request->getUri(), $headers, null, $request);
    }

    /**
     * The app's response as the gate sees it. The body is read only if the
     * gate asks for it (a 200 HTML page for an agent); a streamed body is
     * captured then, and the native response becomes a plain copy of it.
     */
    public static function response(Response $response): GateResponse
    {
        $headers = new Headers();
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ((array) $values as $v) {
                try {
                    $headers->add((string) $name, (string) $v);
                } catch (\InvalidArgumentException) {
                }
            }
        }
        foreach ($response->headers->getCookies() as $cookie) {
            $headers->add('Set-Cookie', (string) $cookie);
        }
        $g = null;
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            $body = static function () use ($response, &$g): string {
                ob_start();
                try {
                    $response->sendContent();
                } finally {
                    $captured = (string) ob_get_clean();
                }
                $copy = new Response($captured, $response->getStatusCode());
                $copy->headers = clone $response->headers;
                $g->native = $copy;
                return $captured;
            };
        } else {
            $body = static fn (): string => (string) $response->getContent();
        }
        $g = new GateResponse($response->getStatusCode(), $headers, $body, $response);
        return $g;
    }

    /** The response to send: the app's own when the gate passed it through, else one built from the gate's. */
    public static function toSymfony(GateResponse $g): Response
    {
        if ($g->native instanceof Response) {
            return $g->native;
        }
        $res = new Response($g->body(), $g->status);
        foreach ($g->headers as [$name, $value]) {
            $res->headers->set($name, $value, false);
        }
        return $res;
    }
}
