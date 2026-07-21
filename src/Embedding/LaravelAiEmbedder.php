<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Embedding;

use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use Laravel\Ai\Embeddings;

/**
 * Default {@see Embedder}, backed by Laravel AI's embeddings API.
 *
 * The provider and model default to the application's configured embedding
 * provider (`ai.default_for_embeddings`) when null. The requested dimension is
 * both passed to the provider and validated against the returned vector, so a
 * provider/model whose output width does not match the configured (and
 * column-fixed) dimension fails loudly rather than corrupting the index.
 */
final class LaravelAiEmbedder implements Embedder
{
    public function __construct(
        private readonly ?string $provider,
        private readonly ?string $model,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = Embeddings::for(array_values($texts))
            ->dimensions($this->dimensions)
            ->generate($this->provider, $this->model);

        $vectors = [];

        foreach ($response->embeddings as $index => $vector) {
            $width = count($vector);

            if ($width !== $this->dimensions) {
                throw new EmbeddingFailedException(sprintf(
                    'Embedding provider returned a %d-dimension vector but swarm-memory-vector.embedding.dimensions is %d. '
                    .'Set the dimensions config to match your provider/model output width.',
                    $width,
                    $this->dimensions,
                ));
            }

            $vectors[$index] = array_map(static fn (int|float $component): float => (float) $component, $vector);
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
