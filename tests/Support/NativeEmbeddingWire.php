<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support;

use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Embedding\LaravelAiEmbedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class NativeEmbeddingWire
{
    public const OPENAI_URL = 'https://api.openai.com/v1/embeddings';

    public const VOYAGE_URL = 'https://api.voyageai.com/v1/embeddings';

    public static function configure(string $provider = 'openai'): LaravelAiEmbedder
    {
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.'.$provider.'.key', 'fake-vector-key');
        Http::preventStrayRequests();

        return new LaravelAiEmbedder($provider, $provider === 'voyageai' ? 'voyage-4' : 'text-embedding-3-small', TestCase::DIMENSIONS);
    }

    public static function bind(): void
    {
        app()->instance(Embedder::class, self::configure());
    }

    /** @return array<int, float> */
    public static function vector(int $axis, float $weight = 1.0): array
    {
        $vector = array_fill(0, TestCase::DIMENSIONS, 0.0);
        $vector[$axis] = $weight;

        return $vector;
    }

    /** @param array<int, array<int, int|float>> $vectors */
    public static function response(array $vectors): array
    {
        return [
            'data' => array_map(static fn (array $vector): array => ['embedding' => $vector], $vectors),
            'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4],
        ];
    }
}
