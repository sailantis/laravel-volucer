<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer;

use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Volucer\Request as VolucerRequest;

/**
 * Translate one volucer request into a Laravel request.
 *
 * Laravel's request *is* a Symfony request with extra behaviour, so the translation has two
 * steps: build a Symfony request from the frame, then convert it with
 * `Illuminate\Http\Request::createFromBase()` — the same path `Request::capture()` takes under a
 * SAPI. Reimplementing that second step would be a second answer to a question Laravel already
 * answers, and the two would drift.
 *
 * ## What a worker does not have
 *
 * Under a SAPI Laravel reads the PHP superglobals. A worker has a method, a URI, headers and an
 * already-read body, so the three that must be reconstructed are the same three every bridge has:
 *
 * - **the body**, parsed for the media types PHP parses and no others;
 * - **the cookies**, parsed from the `Cookie` header, without which every session looks new and
 *   every request starts one;
 * - **`REMOTE_ADDR`**, without which `$request->ip()` is `null` and every rate limiter keys on the
 *   same nothing.
 */
final class RequestFactory
{
    /**
     * @param list<string>|null $trustedProxies CIDRs whose `X-Forwarded-For` is believed; null
     *                                          means "configure nothing", which is the safe default
     */
    public function create(VolucerRequest $request, ?array $trustedProxies = null): LaravelRequest
    {
        $base = $this->symfonyRequest($request);

        if ($trustedProxies !== null) {
            SymfonyRequest::setTrustedProxies($trustedProxies, SymfonyRequest::HEADER_X_FORWARDED_FOR);
        }

        /** @var LaravelRequest $laravel */
        $laravel = LaravelRequest::createFromBase($base);

        // `createFromBase()` copies `$request->content` and re-parses only JSON, because under a
        // SAPI `$_POST` is already populated by PHP. A worker has no `$_POST`, so a *form* body is
        // carried across explicitly — without this `$request->request` is empty and every
        // controller reads "the client sent nothing". The JSON case is left alone: Laravel has
        // already put the decoded object in `request`, and replacing it would undo that.
        $parsed = self::parsedBody($request);
        if ($parsed !== null) {
            $laravel->request->replace($parsed);
        }

        return $laravel;
    }

    /**
     * The Symfony request the conversion starts from.
     *
     * Public so a test can assert the intermediate value: the three fields that are easy to get
     * wrong are all decided here, and by the time `createFromBase()` has run they are one object
     * removed from the cause of the mistake.
     */
    public function symfonyRequest(VolucerRequest $request): SymfonyRequest
    {
        [$host, $port] = self::splitHost($request->header('Host'), $request->https);

        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->uri,
            'QUERY_STRING' => self::queryString($request->uri),
            'SERVER_PROTOCOL' => $request->protocol,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTP_HOST' => $request->header('Host') ?? $host,
            'REMOTE_ADDR' => $request->remoteAddr,
            'SCRIPT_NAME' => '',
            'SCRIPT_FILENAME' => $request->scriptFilename,
            'HTTPS' => $request->https ? 'on' : 'off',
        ];

        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', (string) $name));

            // `Host` is already `HTTP_HOST`, resolved once above. Adding it again produces
            // `example.test, example.test`, which Symfony refuses as a suspicious host — and
            // would be a wrong header even if it accepted it.
            if ($key === 'HOST') {
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $server[$key] = (string) $value;
                continue;
            }

            $server['HTTP_' . $key] = isset($server['HTTP_' . $key])
                ? $server['HTTP_' . $key] . ', ' . $value
                : (string) $value;
        }

        $symfony = SymfonyRequest::create(
            $request->uri,
            $request->method,
            [],
            self::cookies($request->header('Cookie') ?? ''),
            [],
            $server,
            $request->body,
        );

        // `create()` does not parse a body, because under a SAPI PHP has already read it. A
        // worker's body is bytes on the wire, so the two media types Symfony itself parses are
        // parsed here — the same rule `createFromGlobals()` follows, reached from the other side.
        $parsed = self::parsedBody($request);
        if ($parsed !== null) {
            $symfony->request->replace($parsed);
        }

        return $symfony;
    }

    /**
     * The parsed body, for the media types a PHP SAPI would populate `$_POST` from.
     *
     * Only `application/x-www-form-urlencoded`. `multipart/form-data` is deliberately **not**
     * parsed: the server has consumed the request, so the boundary is the only thing left, and a
     * truncated set of files is worse than none — an application cannot tell it from "the client
     * sent nothing". Uploads want the `upstream` backend.
     *
     * @return array<string, mixed>|null
     */
    public static function parsedBody(VolucerRequest $request): ?array
    {
        if ($request->body === '') {
            return null;
        }

        $contentType = strtolower(trim(explode(';', $request->contentType() ?? '')[0]));

        if ($contentType !== 'application/x-www-form-urlencoded') {
            return null;
        }

        $parsed = [];
        // `parse_str` is what a SAPI uses, including its legacy key mangling, so a worker behaves
        // like the deployment the application was tested on rather than tidier than it.
        parse_str($request->body, $parsed);

        return $parsed;
    }

    /**
     * Parse a `Cookie` header the way PHP does.
     *
     * @return array<string, string>
     */
    public static function cookies(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $at = strpos($pair, '=');
            if ($at === false) {
                continue;
            }

            $name = urldecode(substr($pair, 0, $at));
            if ($name === '') {
                continue;
            }

            $cookies[$name] = urldecode(substr($pair, $at + 1));
        }

        return $cookies;
    }

    /**
     * Split a `Host` header into a name and a port, handling a bracketed IPv6 literal.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitHost(?string $host, bool $https): array
    {
        $default = $https ? '443' : '80';

        if ($host === null || trim($host) === '') {
            return ['localhost', $default];
        }

        $host = trim($host);

        if (str_starts_with($host, '[')) {
            $closing = strpos($host, ']');
            if ($closing !== false) {
                $name = substr($host, 0, $closing + 1);
                $rest = substr($host, $closing + 1);

                return str_starts_with($rest, ':') && strlen($rest) > 1
                    ? [$name, substr($rest, 1)]
                    : [$name, $default];
            }

            return [$host, $default];
        }

        if (str_contains($host, ':')) {
            $at = strrpos($host, ':');
            $name = substr($host, 0, $at);
            $port = substr($host, $at + 1);
            if ($name !== '' && $port !== '') {
                return [$name, $port];
            }
        }

        return [$host, $default];
    }

    public static function queryString(string $uri): string
    {
        $at = strpos($uri, '?');

        return $at === false ? '' : substr($uri, $at + 1);
    }
}
