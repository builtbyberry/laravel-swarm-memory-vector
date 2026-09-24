<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use BuiltByBerry\LaravelSwarmMemoryVector\Memory\VectorMemoryStore;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support\ThrowingEmbedder;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

function vectorRows(string $key): int
{
    return DB::table('swarm_memory_vectors')->where('key', $key)->count();
}

test('the vector store is bound as the memory store, wrapped by redaction', function () {
    // Redaction must stay the outermost decorator so the vector store never
    // sees a pre-redaction value.
    expect(app(MemoryStore::class))->toBeInstanceOf(RedactingMemoryStore::class);
});

test('a string memory write is persisted and embedded', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'topic'))->toBe('launch plan');
    expect(DB::table('swarm_memories')->where('key', 'topic')->count())->toBe(1);
    expect(vectorRows('topic'))->toBe(1);
});

test('a non-string memory write is persisted but not embedded', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'count', 42);

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'count'))->toBe(42);
    expect(vectorRows('count'))->toBe(0);
});

test('updating a value re-embeds in place without duplicating', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'first');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'second');

    expect(vectorRows('topic'))->toBe(1);
});

test('changing a value from text to a non-string drops its stale embedding', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'some text');
    expect(vectorRows('topic'))->toBe(1);

    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', ['now' => 'structured']);

    expect(vectorRows('topic'))->toBe(0);
});

test('forget removes both the memory row and its embedding', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');

    app(SwarmMemory::class)->forget(MemoryScope::Run, 'run-1', 'topic');

    expect(DB::table('swarm_memories')->where('key', 'topic')->count())->toBe(0);
    expect(vectorRows('topic'))->toBe(0);
});

test('get and all delegate to the underlying store', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'a', 'alpha');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'b', 'beta');

    $all = app(SwarmMemory::class)->all(MemoryScope::Run, 'run-1');

    expect($all)->toHaveCount(2);
});

test('under the default failure policy a failed embedding still persists memory', function () {
    $this->app->instance(Embedder::class, new ThrowingEmbedder($this::DIMENSIONS));

    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');

    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'topic'))->toBe('launch plan');
    expect(vectorRows('topic'))->toBe(0);
});

test('under the throw failure policy a failed embedding aborts the whole write', function () {
    $store = new VectorMemoryStore(
        inner: app(DatabaseMemoryStore::class),
        index: app(VectorIndex::class),
        embedder: new ThrowingEmbedder($this::DIMENSIONS),
        connection: DB::connection(),
        failOnEmbeddingError: true,
        logger: app(LoggerInterface::class),
    );

    $put = fn () => $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'topic', 'launch plan'));

    expect($put)->toThrow(EmbeddingFailedException::class);
    // Embedding is computed before the transaction opens, so nothing is written.
    expect(DB::table('swarm_memories')->where('key', 'topic')->count())->toBe(0);
});

test('an index failure rolls back canonical and vector writes on the same connection', function (string $operation) {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'original');
    $beforeMemory = (array) DB::table('swarm_memories')->where('key', 'topic')->first();
    $beforeVector = (array) DB::table('swarm_memory_vectors')->where('key', 'topic')->first();
    $realIndex = app(VectorIndex::class);
    $failingIndex = Mockery::mock(VectorIndex::class);
    if ($operation === 'put') {
        $failingIndex->shouldReceive('upsert')->once()->andReturnUsing(function ($scope, $scopeId, $key, $embedding) use ($realIndex) {
            expect(app(DatabaseMemoryStore::class)->get($scope, $scopeId, $key)->value)->toBe('replacement');
            $realIndex->upsert($scope, $scopeId, $key, $embedding);
            throw new RuntimeException('index write failed after mutation');
        });
    } else {
        $failingIndex->shouldReceive('forget')->once()->andReturnUsing(function ($scope, $scopeId, $key) use ($realIndex) {
            expect(DB::table('swarm_memories')->where('key', $key)->exists())->toBeFalse();
            $realIndex->forget($scope, $scopeId, $key);
            expect(DB::table('swarm_memory_vectors')->where('key', $key)->exists())->toBeFalse();
            throw new RuntimeException('index write failed after mutation');
        });
    }
    $store = new VectorMemoryStore(
        inner: app(DatabaseMemoryStore::class),
        index: $failingIndex,
        embedder: app(Embedder::class),
        connection: DB::connection(),
        failOnEmbeddingError: true,
        logger: app(LoggerInterface::class),
    );

    expect(fn () => $operation === 'put'
        ? $store->put(new MemoryEntry(MemoryScope::Run, 'run-1', 'topic', 'replacement'))
        : $store->forget(MemoryScope::Run, 'run-1', 'topic'))
        ->toThrow(RuntimeException::class, 'index write failed after mutation');
    expect((array) DB::table('swarm_memories')->where('key', 'topic')->first())->toBe($beforeMemory);
    expect((array) DB::table('swarm_memory_vectors')->where('key', 'topic')->first())->toBe($beforeVector);
})->with(['put', 'forget']);
