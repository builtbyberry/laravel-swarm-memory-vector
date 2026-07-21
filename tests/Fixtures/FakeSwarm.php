<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Tests\Fixtures;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;

/**
 * Minimal swarm fixture. It declares no `#[PropagationPolicy]`, so it resolves
 * to the core DefaultPropagationPolicy (Run scope only) — the same visibility
 * the standard Recall tool sees.
 */
final class FakeSwarm implements Swarm
{
    public function agents(): array
    {
        return [];
    }
}
