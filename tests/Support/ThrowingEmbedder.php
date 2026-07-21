<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support;

use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use RuntimeException;

/**
 * An {@see Embedder} that always fails, standing in for an unavailable
 * embedding provider so the store's failure-policy behavior can be tested.
 */
final class ThrowingEmbedder implements Embedder
{
    public function __construct(private readonly int $dimensions = 16) {}

    public function embed(string $text): array
    {
        throw new RuntimeException('embedding provider is unavailable');
    }

    public function embedBatch(array $texts): array
    {
        throw new RuntimeException('embedding provider is unavailable');
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
