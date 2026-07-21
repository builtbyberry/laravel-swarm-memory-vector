<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Stores one embedding per memory entry and ranks a set of candidate keys by
 * similarity to a query vector.
 *
 * The index is addressed by the same `(scope, scope_id, key)` tuple as the
 * core memory store, so an embedding row always corresponds to exactly one
 * `swarm_memories` row. Ranking is intentionally scoped to a caller-supplied
 * list of keys rather than the whole table: the vector reader first resolves
 * the exact set of entries the propagation policy permits an agent to see,
 * then asks the index only to *order* that authorized set. A hit the policy
 * withholds can therefore never surface.
 */
interface VectorIndex
{
    /**
     * Insert or replace the embedding for a memory entry.
     *
     * @param  array<int, float>  $embedding
     */
    public function upsert(MemoryScope $scope, string $scopeId, string $key, array $embedding): void;

    /**
     * Drop the embedding for a memory entry, if present.
     */
    public function forget(MemoryScope $scope, string $scopeId, string $key): void;

    /**
     * Rank the given candidate keys within `(scope, scopeId)` by similarity to
     * the query vector, most similar first.
     *
     * Only keys that have a stored embedding are returned; keys absent from the
     * index are omitted. At most `$limit` keys are returned. Implementations
     * that enforce a minimum-similarity threshold drop weaker matches here.
     *
     * @param  array<int, float>  $queryEmbedding
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public function rank(array $queryEmbedding, MemoryScope $scope, string $scopeId, array $keys, int $limit): array;
}
