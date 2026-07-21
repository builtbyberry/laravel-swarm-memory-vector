<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Index;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared storage plumbing for the vector index drivers. Embeddings are encoded
 * as a JSON array of floats — which is also the textual literal PostgreSQL's
 * `vector` type accepts (`[0.1,0.2,...]`) — so both drivers persist an
 * identical on-disk representation and differ only in column type and how they
 * rank.
 */
abstract class AbstractVectorIndex implements VectorIndex
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $table,
        protected readonly ?float $minSimilarity = null,
    ) {}

    public function forget(MemoryScope $scope, string $scopeId, string $key): void
    {
        $this->table()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->where('key', $key)
            ->delete();
    }

    protected function table(): Builder
    {
        return $this->connection->table($this->table);
    }

    /**
     * Encode a vector as the JSON/pgvector text literal `[0.1,0.2,...]`.
     *
     * @param  array<int, float>  $embedding
     */
    protected function encode(array $embedding): string
    {
        return json_encode(array_values($embedding), JSON_THROW_ON_ERROR);
    }

    protected function now(): Carbon
    {
        return Carbon::now('UTC');
    }
}
