<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmMemoryVector\Embedding\LaravelAiEmbedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use Laravel\Ai\Embeddings;

/**
 * Exercises the real Laravel AI-backed embedder through Laravel AI's own fake
 * gateway — so the actual `Embeddings::for()->dimensions()->generate()` call and
 * response parsing are covered, not just the in-test HashEmbedder.
 */
test('it embeds text through Laravel AI and returns a vector of the configured width', function () {
    // With no seeded responses the fake returns vectors of the requested width.
    Embeddings::fake();

    $embedder = new LaravelAiEmbedder(provider: null, model: null, dimensions: 16);

    $vector = $embedder->embed('the rocket launch schedule');

    expect($vector)->toHaveCount(16);
    expect($vector[0])->toBeFloat();
});

test('it embeds a batch, one vector per input', function () {
    Embeddings::fake();

    $embedder = new LaravelAiEmbedder(provider: null, model: null, dimensions: 16);

    $vectors = $embedder->embedBatch(['alpha', 'beta', 'gamma']);

    expect($vectors)->toHaveCount(3);
    expect($vectors[0])->toHaveCount(16);
    expect($vectors[2])->toHaveCount(16);
});

test('it rejects a vector whose width does not match the configured dimensions', function () {
    // The provider returns a 3-dim vector; the embedder is configured for 16.
    Embeddings::fake([[array_fill(0, 3, 0.1)]]);

    $embedder = new LaravelAiEmbedder(provider: null, model: null, dimensions: 16);

    expect(fn () => $embedder->embed('mismatch'))->toThrow(EmbeddingFailedException::class);
});

test('an empty batch returns empty without calling the provider', function () {
    $embedder = new LaravelAiEmbedder(provider: null, model: null, dimensions: 16);

    expect($embedder->embedBatch([]))->toBe([]);
});

test('it reports its configured dimensionality', function () {
    expect((new LaravelAiEmbedder(null, null, 1024))->dimensions())->toBe(1024);
});
