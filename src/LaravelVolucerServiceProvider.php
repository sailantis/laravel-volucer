<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the runner from the application container.
 *
 * A service provider is the smallest thing that can register a runner without changing how an
 * application boots, and it is the shape Laravel packages use. Nothing here overrides a
 * framework service: the runner takes the application's own kernel, so middleware, exception
 * handling and route binding are all the ones the application already configured.
 */
final class LaravelVolucerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/volucer.php', 'volucer');

        $this->app->singleton(WorkerRunner::class, function ($app): WorkerRunner {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('volucer', []);

            return new WorkerRunner(
                $app->make(\Illuminate\Contracts\Http\Kernel::class),
                $app,
                self::trustedProxies($config),
            );
        });
    }

    /**
     * The trust list to hand the request factory.
     *
     * `null` means "configure nothing", which is the right answer for an empty list: the server
     * has already resolved a forwarded address where it was configured to, and believing a header
     * by default is how a spoofed address gets in.
     *
     * @param array<string, mixed> $config
     * @return list<string>|null
     */
    public static function trustedProxies(array $config): ?array
    {
        $proxies = $config['trusted_proxies'] ?? [];

        if (!is_array($proxies) || $proxies === []) {
            return null;
        }

        return array_values(array_map('strval', $proxies));
    }
}
