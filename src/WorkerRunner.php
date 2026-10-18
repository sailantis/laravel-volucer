<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as LaravelRequest;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Volucer\Request as VolucerRequest;
use Volucer\Response as VolucerResponse;
use Volucer\Worker;
use Throwable;


/**
 * Serve a Laravel application from a volucer worker.
 *
 * ## Why this is a standalone bridge and not an Octane driver
 *
 * Octane's engine list is hardcoded in `StartCommand` —
 * `match ($server) { 'swoole' => …, 'roadrunner' => …, 'frankenphp' => …, default => invalidServer() }`
 * — so `octane:start --server=volucer` is an upstream pull request rather than something a package
 * can add. What a package *can* do is what `baldinof/roadrunner-bundle` did for Symfony: boot the
 * framework itself and call into it in a loop. That is this class.
 *
 * ## The reset, which is the acceptance criterion
 *
 * "It serves a request" is not the bar; *two consecutive requests that must not see each other's
 * state* is. Laravel's own answer to this is Octane's, and the important half of it is available
 * without Octane because it lives in the container:
 *
 * - **`forgetScopedInstances()`** drops every `scoped()` binding — which is exactly the set Octane
 *   flushes between requests, and exactly the set Laravel declares request-scoped. This is the
 *   reset; everything else here is supporting it.
 * - **the `request` binding is replaced** each time, because a stale request in the container is
 *   how a controller reads the previous client's input.
 * - **facades are cleared**, because `Facade` caches resolved instances statically and a facade
 *   resolved against request 1 would otherwise answer for request 2.
 * - **the HTTP kernel's terminate runs**, so a `terminable` middleware does its job — after the
 *   response is read, since the protocol declares the body length before the body.
 *
 * What is **not** done is re-running the bootstrap. A warm container is the whole point: a Laravel
 * boot is milliseconds and redoing it per request is the deployment model this exists to replace.
 */
final class WorkerRunner
{
    private readonly RequestFactory $requests;

    /**
     * @param list<string>|null $trustedProxies
     */
    public function __construct(
        private readonly Kernel $kernel,
        private readonly ResettableContainer $app,
        private readonly ?array $trustedProxies = null,
        ?RequestFactory $requests = null,
    ) {
        $this->requests = $requests ?? new RequestFactory();
    }

    /**
     * Serve requests until the server closes the channel.
     */
    public function run(?Worker $worker = null): void
    {
        $worker ??= Worker::connect();

        $worker->run(function (VolucerRequest $request, Worker $worker): VolucerResponse {
            try {
                return $this->dispatch($request);
            } catch (Throwable $error) {
                // One bad request must not cost the worker: a long-lived process that dies takes
                // the requests behind it with it. Reported through the SDK's log frame, which is
                // the supported way to say something outside a request.
                $worker->emitLog(
                    'unhandled ' . $error::class . ': ' . $error->getMessage(),
                    'error',
                );

                return new VolucerResponse(
                    500,
                    [['Content-Type', 'text/plain; charset=utf-8']],
                    "Internal Server Error\n",
                );
            } finally {
                $this->reset();
            }
        });
    }

    /**
     * Run one request through the HTTP kernel.
     *
     * Public and free of I/O so the pipeline — and the reset — can be tested without a pipe.
     */
    public function dispatch(VolucerRequest $request): VolucerResponse
    {
        $laravel = $this->requests->create($request, $this->trustedProxies);

        self::populateSuperglobals($laravel);

        $response = $this->kernel->handle($laravel);

        // `terminate` is what a front controller calls after the response is sent, so it is called
        // here after the response is *read*: a `terminable` middleware is allowed to write to the
        // response, and the protocol's `body_len` is declared before the body.
        $this->kernel->terminate($laravel, $response);

        return self::toVolucerResponse($response);
    }

    /**
     * Reset what the next request must not inherit.
     *
     * Public because it is the property under test, and because an application with its own
     * request-scoped services should be able to see exactly what is being reset.
     */
    public function reset(): void
    {
        // The whole of Laravel's own answer. Octane calls this between requests and it is what
        // makes `scoped()` mean "per request" rather than "per process".
        $this->app->forgetScopedInstances();

        // A facade resolved during request 1 holds a resolved instance; without this it would
        // answer for request 2.
        Facade::clearResolvedInstances();

        // The request binding itself, which is not a `scoped()` binding in every Laravel version.
        $this->app->forgetInstance('request');

        self::clearSuperglobals();
    }

    /**
     * A Symfony/Laravel response as the SDK's.
     *
     * `headers->all()` returns a list per name, which is what the wire wants: two `Set-Cookie`
     * headers are two cookies, and joining them would silently break a session.
     */
    public static function toVolucerResponse(SymfonyResponse $response): VolucerResponse
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = [(string) $name, (string) $value];
            }
        }

        $body = $response->getContent();
        if ($body === false) {
            // A `StreamedResponse` has no content of its own. Reading the stream is the honest
            // thing to do, because the protocol declares `body_len` before the body and a
            // response that streams cannot be sent at all.
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        }

        return new VolucerResponse($response->getStatusCode(), $headers, (string) $body);
    }

    /**
     * Mirror the request into the superglobals a SAPI would have filled.
     *
     * Not decoration: Laravel's session middleware and many packages read `$_SERVER` and
     * `$_COOKIE` directly rather than through the request object. A bridge that populated only
     * the request would work for a plain route and break the moment a session was involved.
     */
    private static function populateSuperglobals(LaravelRequest $request): void
    {
        $_SERVER = $request->server->all();
        $_GET = $request->query->all();
        $_POST = $request->request->all();
        $_COOKIE = $request->cookies->all();
        $_FILES = [];
        $_REQUEST = $_GET + $_POST;
    }

    private static function clearSuperglobals(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
    }
}
