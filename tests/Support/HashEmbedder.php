<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support;

use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;

/**
 * Deterministic, network-free {@see Embedder} for tests. Hashes word tokens
 * into a fixed-width bag-of-words vector, so texts that share words produce
 * similar vectors — enough structure to exercise similarity ranking
 * meaningfully without calling a real embedding provider.
 */
final class HashEmbedder implements Embedder
{
    public function __construct(private readonly int $dimensions = 16) {}

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        return array_values(array_map(
            fn (string $text): array => $this->vectorFor($text),
            array_values($texts),
        ));
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @return array<int, float>
     */
    private function vectorFor(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $vector[crc32($token) % $this->dimensions] += 1.0;
        }

        // Never emit an all-zero vector (undefined cosine).
        if (array_sum($vector) === 0.0) {
            $vector[0] = 1.0;
        }

        return $vector;
    }
}
