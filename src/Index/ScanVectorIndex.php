<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Index;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarmMemoryVector\Support\Cosine;

/**
 * Portable vector index: stores each embedding as JSON text and ranks the
 * candidate set with an in-PHP cosine similarity. Works on any database
 * (sqlite, mysql, pgsql without the vector extension). Because ranking is
 * always scoped to the small, already-authorized candidate set the reader
 * passes in — never the whole table — the PHP-side scan stays cheap.
 */
final class ScanVectorIndex extends AbstractVectorIndex
{
    public function upsert(MemoryScope $scope, string $scopeId, string $key, array $embedding): void
    {
        $now = $this->now();

        $this->table()->upsert(
            [[
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'key' => $key,
                'embedding' => $this->encode($embedding),
                'dimensions' => count($embedding),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['scope', 'scope_id', 'key'],
            ['embedding', 'dimensions', 'updated_at'],
        );
    }

    public function rank(array $queryEmbedding, MemoryScope $scope, string $scopeId, array $keys, int $limit): array
    {
        if ($keys === [] || $limit < 1) {
            return [];
        }

        $rows = $this->table()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->whereIn('key', array_values($keys))
            ->get(['key', 'embedding']);

        $scored = [];

        foreach ($rows as $row) {
            /** @var array<int, float>|null $vector */
            $vector = json_decode((string) $row->embedding, true);

            if (! is_array($vector)) {
                continue;
            }

            $similarity = Cosine::similarity($queryEmbedding, $vector);

            if ($this->minSimilarity !== null && $similarity < $this->minSimilarity) {
                continue;
            }

            $scored[] = ['key' => (string) $row->key, 'similarity' => $similarity];
        }

        usort($scored, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_map(
            static fn (array $hit): string => $hit['key'],
            array_slice($scored, 0, $limit),
        );
    }
}
