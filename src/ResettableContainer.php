<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer;

/**
 * The container methods this bridge needs.
 *
 * Narrower than `Application` on purpose: two methods are used, and typing the runner against the
 * whole interface would force every test to construct a booted application to prove something that
 * is a property of the container. `Application` satisfies it, so production is unaffected.
 *
 * **No return types**, because `Illuminate\Container\Container` declares neither and a narrower
 * declaration here would make the framework's own class incompatible with this interface.
 */
interface ResettableContainer extends \Psr\Container\ContainerInterface
{
    /**
     * Drop every `scoped()` binding - Laravel's own per-request reset.
     *
     * This is the method Octane calls between requests, and the reason `scoped()` means "per
     * request" rather than "per process".
     */
    public function forgetScopedInstances();

    /**
     * Drop a bound instance, for the `request` binding that is not always `scoped()`.
     *
     * @param string $abstract
     */
    public function forgetInstance($abstract);
}
