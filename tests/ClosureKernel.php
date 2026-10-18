<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * A kernel backed by a closure, implementing the two methods the runner calls.
 *
 * The interface is implemented rather than a stand-in typed loosely, because the runner takes
 * `Kernel` - so a fake that did not implement it would be testing a different signature than the
 * one production uses.
 */
final class ClosureKernel implements KernelContract
{
    /** @var list<string> */
    public array $terminated = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function __construct(
        private $handler,
        private readonly Container $container,
    ) {
    }

    public function handle($request)
    {
        // The `request` binding a real kernel sets, so a controller resolving it gets what it
        // expects - and so the test can assert the runner forgets it between requests.
        $this->container->instance('request', $request);
        Facade::clearResolvedInstance('request');

        return ($this->handler)($request);
    }

    public function terminate($request, $response): void
    {
        $this->terminated[] = $request->path();
    }

    public function bootstrap(): void
    {
    }

    public function getApplication()
    {
        return $this->container;
    }
}
