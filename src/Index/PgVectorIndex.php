<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Index;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use Illuminate\Database\Grammar;

/**
 * Native pgvector index: stores embeddings in a `vector(N)` column and ranks
 * with PostgreSQL's `<=>` cosine-distance operator over an HNSW index. Ranking
 * is still constrained to the caller's authorized key set (via an `IN` filter),
 * so the propagation policy is honored even on the fast path.
 *
 * pgvector reports cosine *distance* (`1 - similarity`, range 0..2); the
 * minimum-similarity threshold is therefore expressed as a maximum distance.
 */
final class PgVectorIndex extends AbstractVectorIndex
{
    public function upsert(MemoryScope $scope, string $scopeId, string $key, array $embedding): void
    {
        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->table);
        $now = $this->now()->toDateTimeString();

        $columns = implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            ['scope', 'scope_id', 'key', 'embedding', 'dimensions', 'created_at', 'updated_at'],
        ));

        $sql = "insert into {$table} ({$columns}) values (?, ?, ?, ?::vector, ?, ?, ?) "
            .'on conflict ('.$this->wrapList($grammar, ['scope', 'scope_id', 'key']).') do update set '
            .$this->excludedAssignment($grammar, 'embedding').', '
            .$this->excludedAssignment($grammar, 'dimensions').', '
            .$this->excludedAssignment($grammar, 'updated_at');

        $this->connection->statement($sql, [
            $scope->value,
            $scopeId,
            $key,
            $this->encode($embedding),
            count($embedding),
            $now,
            $now,
        ]);
    }

    public function rank(array $queryEmbedding, MemoryScope $scope, string $scopeId, array $keys, int $limit): array
    {
        if ($keys === [] || $limit < 1) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $table = $grammar->wrapTable($this->table);
        $keyColumn = $grammar->wrap('key');
        $embeddingColumn = $grammar->wrap('embedding');

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $vector = $this->encode($queryEmbedding);

        $bindings = [$scope->value, $scopeId, ...array_values($keys)];

        $threshold = '';

        if ($this->minSimilarity !== null) {
            $threshold = " and ({$embeddingColumn} <=> ?::vector) <= ?";
            $bindings[] = $vector;
            $bindings[] = 1.0 - $this->minSimilarity;
        }

        $sql = "select {$keyColumn} from {$table} "
            .'where '.$grammar->wrap('scope').' = ? and '.$grammar->wrap('scope_id').' = ? '
            ."and {$keyColumn} in ({$placeholders})"
            .$threshold
            ." order by {$embeddingColumn} <=> ?::vector limit ".max(1, $limit);

        $bindings[] = $vector;

        $rows = $this->connection->select($sql, $bindings);

        return array_map(
            static fn (object $row): string => (string) ((array) $row)['key'],
            $rows,
        );
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function wrapList(Grammar $grammar, array $columns): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => $grammar->wrap($column),
            $columns,
        ));
    }

    private function excludedAssignment(Grammar $grammar, string $column): string
    {
        $wrapped = $grammar->wrap($column);

        return "{$wrapped} = excluded.{$wrapped}";
    }
}
