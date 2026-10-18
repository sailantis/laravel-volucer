<?php

declare(strict_types=1);

/**
 * The worker entry point the end-to-end test runs.
 *
 * It is what a real front controller would be: boot the kernel, hand it to the runner. The only
 * difference is the kernel, which is {@see \Sailantis\LaravelVolucer\Tests\ClosureKernel} - so
 * this proves the *bridge* rather than an application.
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Sailantis\LaravelVolucer\Tests\ClosureKernel;
use Sailantis\LaravelVolucer\Tests\ResettableTestContainer;
use Sailantis\LaravelVolucer\WorkerRunner;

$container = new ResettableTestContainer();
Facade::setFacadeApplication($container);

$kernel = new ClosureKernel(
    static function (Request $request): JsonResponse {
        return new JsonResponse([
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'post' => $request->request->all(),
            'cookies' => $request->cookies->all(),
        ]);
    },
    $container,
);

(new WorkerRunner($kernel, $container))->run();
