# laravel-volucer

Run a Laravel application on [volucer](https://github.com/sailantis/volucer)'s persistent worker
protocol.

## Why a standalone bridge and not an Octane driver

Octane's engine list is **hardcoded** in `StartCommand`:

```php
match ($server) {
    'swoole' => …, 'roadrunner' => …, 'frankenphp' => …, default => invalidServer(…)
}
```

So `octane:start --server=volucer` is an upstream pull request rather than something a package can
add. What a package *can* do is what `baldinof/roadrunner-bundle` did for Symfony: boot the
framework itself and call into it in a loop. That is this package.

The alternative is the server's `upstream` backend, which supervises `php artisan serve` or
FrankenPHP over HTTP and needs no package. This one exists for the case where the worker channel
itself is what you want: `kv`, `lock`, `jobs` and `metrics`, recycling on RSS, and no HTTP framing
on the hot path.

## Install

```console
composer require sailantis/laravel-volucer
```

Register the provider in `bootstrap/providers.php`:

```php
return [
    // …
    Sailantis\LaravelVolucer\LaravelVolucerServiceProvider::class,
];
```

## Use

```php
<?php
// volucer-worker.php — the entry point

use Illuminate\Contracts\Http\Kernel;
use Sailantis\LaravelVolucer\WorkerRunner;

define('LARAVEL_START', microtime(true));

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app->make(WorkerRunner::class)->run();
```

`run()` calls `Worker::connect()`, so `VOLUCER_WORKER_SOCKET` is honoured and stdio is the
fallback — the call a worker entry point must make, because `Worker::create()` would hang on the
socket transport.

Point a backend at it:

```toml
[backends.laravel]
kind    = "worker"
command = "php volucer-worker.php"
workers = 8
# memory_limit_mb = 256
```

## The reset, which is the acceptance criterion

"It serves a request" is not the bar; **two consecutive requests that must not see each other's
state** is. Laravel's own answer to this is Octane's, and the half that matters lives in the
container rather than in Octane — so it is available here:

| What | How |
| --- | --- |
| Request-scoped services | **`forgetScopedInstances()`** drops every `scoped()` binding. This *is* Octane's per-request flush, and it is the reason `scoped()` means "per request" rather than "per process". |
| Facades | `Facade::clearResolvedInstances()`, because a facade resolved during request 1 would otherwise answer for request 2. |
| The `request` binding | forgotten explicitly — it is not a `scoped()` binding in every Laravel version, and a stale one is how a controller reads the previous client's input. |
| `terminable` middleware | `$kernel->terminate()`, called **after** the response is read — the protocol declares `body_len` before the body, so a middleware must not be able to change it afterwards. |
| `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST` | cleared, because some packages read them directly and PHP leaves them behind in a long-lived process. |

What is deliberately **not** done is re-running the bootstrap. A warm container is the whole point:
a Laravel boot is milliseconds, and redoing it per request is the deployment model this replaces.

## The two things a worker cannot copy

Both live in `RequestFactory` and both fail silently:

**`$request->request` is empty unless the form body is carried across.** `Request::createFromBase()`
copies `$request->content` and re-parses **only JSON**, because under a SAPI `$_POST` is already
populated by PHP. A worker has no `$_POST`, so `application/x-www-form-urlencoded` is parsed and
replaced explicitly. Without it every controller reads "the client sent nothing".

**`$request->cookies` is empty unless the `Cookie` header is parsed.** `Request::create()` does not
do it, and without it every session looks new and every request starts one.

`multipart/form-data` is deliberately **not** parsed: the server has consumed the request, so the
boundary is the only thing left, and a truncated set of files is worse than none. Uploads want the
`upstream` backend.

## Configuration

```php
// config/volucer.php
return [
    'trusted_proxies' => ['10.0.0.0/8'],
];
```

**Empty by default, and the header is ignored when it is.** A worker has no socket, so it cannot
tell a proxy from a client; the server has already resolved the forwarded address where it was
configured to, and believing a header by default is how a client chooses its own `ip()`.

## Tests

```console
composer test
```

16 tests. Two drive a **real `volucer` binary with a real worker process**, because the two things
that can go wrong here — a reset that never ran, a form body that never arrived — are exactly what a
self-consistent fixture cannot see.

They skip without a binary and are found at `VOLUCER_BIN` or `../volucer/target/debug/volucer`.
`VOLUCER_E2E=1` makes a skip a failure, which is what CI sets.

## What this package does not do

- **Multiplexed or async requests.** The channel carries one request at a time, so concurrency is
  `workers` — one process per worker. A protocol property, not an adapter gap.
- **Streamed responses.** `RESPONSE` declares `body_len` before the body. A `StreamedResponse` is
  read into memory and sent whole.
- **`octane:start --server=volucer`.** That needs the upstream change described above; until then a
  deployment runs the entry point directly under `[backends.…]`.
- **`multipart/form-data` uploads.**
