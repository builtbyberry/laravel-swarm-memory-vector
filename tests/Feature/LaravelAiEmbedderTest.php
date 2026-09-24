<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmMemoryVector\Embedding\LaravelAiEmbedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support\NativeEmbeddingWire as Wire;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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

test('native embedding HTTP contracts preserve provider model ordered text and dimension keys', function (string $provider, string $url, string $dimensionKey, string $model) {
    $embedder = Wire::configure($provider);
    $first = array_fill(0, 16, 1);
    $second = array_fill(0, 16, 0.25);
    Http::fake([
        $url => Http::response(
            Wire::response([$first, $second]),
        ),
    ]);

    expect($embedder->embedBatch([7 => 'alpha', 12 => 'beta']))
        ->toBe([array_fill(0, 16, 1.0), $second]);

    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer fake-vector-key')
        && $request['model'] === $model
        && $request['input'] === ['alpha', 'beta']
        && $request[$dimensionKey] === 16);
    Http::assertSentCount(1);
})->with([
    'OpenAI native' => ['openai', 'https://api.openai.com/v1/embeddings', 'dimensions', 'text-embedding-3-small'],
    'Voyage native' => ['voyageai', 'https://api.voyageai.com/v1/embeddings', 'output_dimension', 'voyage-4'],
]);

test('native wire dimensions are checked before returning an embedding', function () {
    $embedder = Wire::configure();
    Http::fake([
        Wire::OPENAI_URL => Http::response(['data' => [['embedding' => [1, 2, 3]]]]),
    ]);

    expect(fn () => $embedder->embed('wrong width'))->toThrow(EmbeddingFailedException::class, '3-dimension');
    Http::assertSentCount(1);
});

test('native wire HTTP errors remain failures and empty batches do not call HTTP', function () {
    $embedder = Wire::configure();
    Http::fake([
        Wire::OPENAI_URL => Http::response(['error' => ['message' => 'Invalid test request']], 400),
    ]);

    expect($embedder->embedBatch([]))->toBe([]);
    Http::assertNothingSent();
    expect(fn () => $embedder->embed('failure'))->toThrow(RequestException::class);
    Http::assertSentCount(1);
});
