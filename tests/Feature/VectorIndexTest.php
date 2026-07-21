<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Exercises whichever index driver is bound for the active connection: the
 * portable ScanVectorIndex on sqlite, and the native PgVectorIndex on a real
 * pgvector PostgreSQL. Same assertions, both drivers — so the pgvector SQL
 * (`?::vector`, `<=>`, the HNSW-backed ORDER BY) is dogfooded in CI.
 */

/**
 * Build a DIMENSIONS-wide unit-ish vector with the given non-zero components,
 * matching the fixed width of the pgvector column.
 *
 * @param  array<int, float>  $components  index => value
 * @return array<int, float>
 */
function vec(array $components): array
{
    $vector = array_fill(0, TestCase::DIMENSIONS, 0.0);

    foreach ($components as $index => $value) {
        $vector[$index] = $value;
    }

    return $vector;
}

function index(): VectorIndex
{
    return app(VectorIndex::class);
}

test('it ranks candidate keys by similarity, most similar first', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-1', 'banana', vec([1 => 1.0]));

    $ranked = index()->rank(vec([0 => 0.9, 1 => 0.1]), MemoryScope::Run, 'run-1', ['apple', 'banana'], 5);

    expect($ranked)->toBe(['apple', 'banana']);
});

test('it only ranks the caller-supplied candidate keys', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-1', 'banana', vec([1 => 1.0]));

    $ranked = index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-1', ['banana'], 5);

    expect($ranked)->toBe(['banana']);
});

test('it scopes ranking to the given scope id', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-2', 'apple', vec([0 => 1.0]));

    $ranked = index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-2', ['apple'], 5);

    expect($ranked)->toBe(['apple']);
    expect(DB::table('swarm_memory_vectors')->count())->toBe(2);
});

test('it honors a minimum-similarity threshold', function () {
    config()->set('swarm-memory-vector.search.min_similarity', 0.5);
    app()->forgetInstance(VectorIndex::class);

    index()->upsert(MemoryScope::Run, 'run-1', 'aligned', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-1', 'orthogonal', vec([1 => 1.0]));

    $ranked = index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-1', ['aligned', 'orthogonal'], 5);

    expect($ranked)->toBe(['aligned']);
});

test('it caps results at the requested limit', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'a', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-1', 'b', vec([0 => 0.9, 1 => 0.1]));
    index()->upsert(MemoryScope::Run, 'run-1', 'c', vec([0 => 0.8, 1 => 0.2]));

    $ranked = index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-1', ['a', 'b', 'c'], 2);

    expect($ranked)->toHaveCount(2);
    expect($ranked[0])->toBe('a');
});

test('upsert replaces an existing embedding rather than duplicating it', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([0 => 1.0]));
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([1 => 1.0]));

    expect(DB::table('swarm_memory_vectors')->where('key', 'apple')->count())->toBe(1);
    expect(index()->rank(vec([1 => 1.0]), MemoryScope::Run, 'run-1', ['apple'], 5))->toBe(['apple']);
});

test('forget removes an embedding from the index', function () {
    index()->upsert(MemoryScope::Run, 'run-1', 'apple', vec([0 => 1.0]));

    index()->forget(MemoryScope::Run, 'run-1', 'apple');

    expect(index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-1', ['apple'], 5))->toBe([]);
});

test('rank returns nothing for an empty candidate set', function () {
    expect(index()->rank(vec([0 => 1.0]), MemoryScope::Run, 'run-1', [], 5))->toBe([]);
});
