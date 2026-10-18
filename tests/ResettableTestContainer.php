<?php

declare(strict_types=1);

namespace Sailantis\LaravelVolucer\Tests;

use Illuminate\Container\Container;
use Sailantis\LaravelVolucer\ResettableContainer;

/**
 * The **real** Laravel container, plus the interface the runner needs.
 *
 * Extending `Container` rather than stubbing it is deliberate: `forgetScopedInstances()` is the
 * framework's own implementation, and the property under test is what that implementation does. A
 * stand-in would prove the test rather than the bridge.
 */
final class ResettableTestContainer extends Container implements ResettableContainer
{
    /** @var list<string> */
    public array $forgottenInstances = [];

    public function forgetInstance($abstract)
    {
        $this->forgottenInstances[] = (string) $abstract;

        return parent::forgetInstance($abstract);
    }
}
