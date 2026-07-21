<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Contracts;

/**
 * Turns text into embedding vectors.
 *
 * The default binding wraps Laravel AI's embeddings API, but any provider can
 * be bound in the container — a local model, a stubbed embedder for tests, etc.
 * Implementations must return vectors of exactly {@see dimensions()} floats so
 * they round-trip through the fixed-width vector column.
 */
interface Embedder
{
    /**
     * Embed a single string.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Embed a batch of strings, returning one vector per input in input order.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * The fixed dimensionality of every vector this embedder produces.
     */
    public function dimensions(): int;
}
