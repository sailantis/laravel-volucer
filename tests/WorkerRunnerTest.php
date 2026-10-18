<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Sailantis\LaravelVolucer\WorkerRunner;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Volucer\Frame;
use Volucer\Header;
use Volucer\MessageType;
use Volucer\Request as VolucerRequest;
use Volucer\Worker;

/**
 * The reset, which is the acceptance criterion rather than "it serves a request".
 *
 * The runner is exercised with a kernel backed by a closure and the **real** `Container`, so
 * `forgetScopedInstances()` is the framework's own implementation rather than a stand-in. What is
 * asserted is that the bridge calls it: a scoped binding registered during request 1 must be gone
 * by request 2, and the request binding must be replaced rather than left behind.
 */
final class WorkerRunnerTest extends \PHPUnit\Framework\TestCase
{
    private static function request(array $overrides = []): VolucerRequest
    {
        return new VolucerRequest(
            method: $overrides['method'] ?? 'GET',
            uri: $overrides['uri'] ?? '/',
            protocol: $overrides['protocol'] ?? 'HTTP/1.1',
            headers: $overrides['headers'] ?? ['Host' => 'example.test'],
            remoteAddr: $overrides['remoteAddr'] ?? '127.0.0.1',
            scriptFilename: $overrides['scriptFilename'] ?? '/srv/app/public/index.php',
            https: $overrides['https'] ?? false,
            body: $overrides['body'] ?? '',
        );
    }

    /**
     * A runner on a real container, with a closure as the kernel.
     *
     * @param callable(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response $handler
     * @return array{0: WorkerRunner, 1: ResettableTestContainer}
     */
    private function runner(callable $handler): array
    {
        $container = new ResettableTestContainer();
        // `Facade` needs a resolved application, so the container is what it points at.
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $kernel = new ClosureKernel($handler, $container);

        return [new WorkerRunner($kernel, $container), $container];
    }

    public function test_one_request_reaches_the_kernel_and_comes_back(): void
    {
        [$runner] = $this->runner(static function (\Illuminate\Http\Request $request) {
            return new \Illuminate\Http\JsonResponse([
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => $request->query(),
                'post' => $request->request->all(),
                'cookies' => $request->cookies->all(),
                'ip' => $request->ip(),
                'scheme' => $request->getScheme(),
            ]);
        });

        $response = $runner->dispatch(self::request([
            'method' => 'POST',
            'uri' => '/items?id=7',
            'headers' => [
                'Host' => 'example.test',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'name=widget',
            'remoteAddr' => '10.0.0.7',
        ]));

        self::assertSame(200, $response->status);
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('POST', $payload['method']);
        self::assertSame('items', $payload['path']);
        self::assertSame(['id' => '7'], $payload['query']);
        self::assertSame(['name' => 'widget'], $payload['post']);
        self::assertSame('10.0.0.7', $payload['ip']);
        self::assertSame('http', $payload['scheme']);
    }

    public function test_a_scoped_binding_does_not_survive_the_request(): void
    {
        // The whole point of `forgetScopedInstances()`, and the reason this bridge is a bridge
        // rather than a loop: `scoped()` means "per request" and the runner is what makes it so.
        $seen = [];
        $containerRef = null;
        [$runner, $container] = $this->runner(
            static function (\Illuminate\Http\Request $request) use (&$seen, &$containerRef) {
                // Registered once; `scoped()` is a singleton registration, so the second request
                // must get a *fresh* resolution rather than the instance request 1 left behind.
                $containerRef->scoped('request.marker', static fn () => $request->path());
                $seen[] = $containerRef->make('request.marker');

                return new \Illuminate\Http\Response($request->path(), 200);
            },
        );
        // Bound after construction so the closure can reach *this* container: capturing it from
        // `list()` would capture the null the variable held at closure-creation time.
        $containerRef = $container;

        $this->drive($runner, [self::request(['uri' => '/first']), self::request(['uri' => '/second'])]);

        self::assertSame(
            ['first', 'second'],
            $seen,
            'each request got a fresh scoped value, which is what forgetScopedInstances() buys',
        );
        // The observable effect: a resolution after the loop runs the factory again against
        // whatever `$request` was last in scope, which here is the *test's* closure ? so the
        // value is not request 2's path. That is the difference between a scoped singleton and a
        // process-wide one.
        self::assertSame(
            'second',
            $seen[1],
            'request 2 resolved its own instance',
        );
    }

    public function test_the_request_binding_is_replaced_rather_than_left_behind(): void
    {
        // A stale `request` in the container is how a controller reads the previous client's
        // input. The runner forgets it as well as flushing scoped instances, because it is not a
        // `scoped()` binding in every Laravel version.
        $paths = [];
        $containerRef = null;
        [$runner, $container] = $this->runner(
            static function (\Illuminate\Http\Request $request) use (&$paths, &$containerRef) {
                $paths[] = $containerRef->make('request')->path();

                return new \Illuminate\Http\Response('ok', 200);
            },
        );
        $containerRef = $container;

        $this->drive($runner, [self::request(['uri' => '/first']), self::request(['uri' => '/second'])]);

        self::assertSame(['first', 'second'], $paths);
    }

    public function test_two_requests_do_not_see_each_others_state(): void
    {
        // What the bridge guarantees is the *request*: query, form body, cookies and address all
        // arrive per request, and the second one starts empty. The superglobals are populated for
        // the packages that read them directly, and the `finally` in `run()` clears them - which
        // is what the last assertion covers.
        $seen = [];
        [$runner] = $this->runner(
            static function (\Illuminate\Http\Request $request) use (&$seen) {
                $seen[] = [
                    $request->query(),
                    $request->request->all(),
                    $request->cookies->all(),
                    $request->ip(),
                ];

                return new \Illuminate\Http\Response('ok', 200);
            },
        );

        $this->drive($runner, [
            self::request([
                'uri' => '/first?x=1',
                'method' => 'POST',
                'headers' => [
                    'Host' => 'example.test',
                    'Cookie' => 'a=1',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => 'posted=1',
                'remoteAddr' => '10.0.0.1',
            ]),
            self::request(['uri' => '/second', 'remoteAddr' => '10.0.0.2']),
        ]);

        self::assertSame(
            [['x' => '1'], ['posted' => '1'], ['a' => '1'], '10.0.0.1'],
            $seen[0],
            'the first request carried its query, body, cookies and address',
        );
        self::assertSame(
            [[], [], [], '10.0.0.2'],
            $seen[1],
            'the second request must not inherit any of them',
        );

        // And the bridge's own reset: nothing survives into the process-level superglobals.
        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_COOKIE);
        self::assertSame([], $_SERVER);
    }

    public function test_a_throwing_route_keeps_the_worker_serving(): void
    {
        $calls = 0;
        [$runner] = $this->runner(static function (\Illuminate\Http\Request $request) use (&$calls) {
            $calls++;

            if ($request->path() === 'boom') {
                throw new \RuntimeException('boom');
            }

            return new \Illuminate\Http\Response($request->path(), 200);
        });

        $answers = $this->drive($runner, [self::request(['uri' => '/boom']), self::request(['uri' => '/after'])]);

        self::assertSame(2, $calls);
        self::assertSame(500, $answers[0]['status'], 'a throwing route is a 500, not a dead worker');
        self::assertSame(200, $answers[1]['status']);
        self::assertSame('after', $answers[1]['body']);
    }

    public function test_a_response_keeps_every_set_cookie(): void
    {
        [$runner] = $this->runner(static function () {
            $response = new \Illuminate\Http\Response('ok', 200);
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', '1'));
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('b', '2'));

            return $response;
        });

        $response = $runner->dispatch(self::request(['uri' => '/']));
        $cookies = array_values(array_filter(
            $response->headers,
            static fn (array $pair): bool => strcasecmp($pair[0], 'Set-Cookie') === 0,
        ));

        self::assertCount(2, $cookies, 'two cookies are two headers');
    }

    /**
     * Drive a runner over an in-memory channel.
     *
     * The real loop is used rather than calling `dispatch()` twice, because the `finally` that
     * resets is inside `run()`; a test that skipped it would assert the wrong function.
     *
     * @param list<VolucerRequest> $requests
     * @return list<array{status: int, body: string}>
     */
    private function drive(WorkerRunner $runner, array $requests): array
    {
        $server = fopen('php://memory', 'r+');
        $client = fopen('php://memory', 'r+');

        fwrite($client, (new Frame(MessageType::HELLO, 0))->encode());

        foreach ($requests as $index => $request) {
            $payload = json_encode([
                'method' => $request->method,
                'uri' => $request->uri,
                'protocol' => $request->protocol,
                // The wire carries an ordered list of pairs, not a map: two `Set-Cookie` headers
                // must stay two, and the SDK's `Request` collapses them only after reading this.
                'headers' => array_map(
                    static fn (string $name, string $value): array => [$name, $value],
                    array_keys($request->headers),
                    array_values($request->headers),
                ),
                'remote_addr' => $request->remoteAddr,
                'body_len' => strlen($request->body),
                'script_filename' => $request->scriptFilename,
                'https' => $request->https,
            ], JSON_THROW_ON_ERROR);

            fwrite($client, (new Frame(MessageType::REQUEST, $index + 1, $payload))->encode());
            if ($request->body !== '') {
                fwrite($client, (new Frame(
                    MessageType::BODY,
                    $index + 1,
                    $request->body,
                    Header::FLAG_LAST,
                ))->encode());
            }
        }

        rewind($client);
        $runner->run(new Worker($client, $server, fopen('php://memory', 'w+')));

        rewind($server);
        $raw = (string) stream_get_contents($server);

        $answers = [];
        $offset = 0;
        while ($offset < strlen($raw)) {
            $header = Header::decode(substr($raw, $offset, Header::HEADER_LEN));
            $payload = substr($raw, $offset + Header::HEADER_LEN, $header->payloadLen);
            $offset += Header::HEADER_LEN + $header->payloadLen;

            if ($header->messageType === MessageType::RESPONSE) {
                $head = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $answers[] = ['status' => $head['status'], 'body' => ''];
            } elseif ($header->messageType === MessageType::BODY) {
                $answers[count($answers) - 1]['body'] .= $payload;
            }
        }

        return $answers;
    }
}
