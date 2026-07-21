<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use BuiltByBerry\LaravelSwarmMemoryVector\Support\EmbeddingText;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A {@see MemoryStore} decorator that keeps a semantic embedding alongside
 * every persisted memory entry.
 *
 * It delegates the entry's canonical persistence to the wrapped core store
 * (unchanged `swarm_memories` rows, timestamps, lifecycle events, run-id
 * cascade) and additionally maintains a per-entry embedding in the companion
 * vector table. Because the core `RedactingMemoryStore` decorates *this* store,
 * the entry reaching `put()` is already capture-policy-redacted — so the text
 * that gets embedded is the same text that gets persisted, never a
 * pre-redaction value.
 *
 * Transaction boundary: `put()`/`forget()` coordinate two tables (the memory
 * row and its embedding row) on one connection and commit them in a single
 * transaction. The embedding provider call — the only slow, fallible step — is
 * made *before* the transaction opens, so a network round-trip never holds a
 * database transaction open, and an embedding failure is resolved (skip or
 * throw) before any row is written.
 */
final class VectorMemoryStore implements MemoryStore
{
    public function __construct(
        private readonly MemoryStore $inner,
        private readonly VectorIndex $index,
        private readonly Embedder $embedder,
        private readonly ConnectionInterface $connection,
        private readonly bool $failOnEmbeddingError,
        private readonly LoggerInterface $logger,
    ) {}

    public function put(MemoryEntry $entry): MemoryEntry
    {
        $text = EmbeddingText::from($entry->value);

        $embedding = $text !== null ? $this->embed($text, $entry) : null;

        return $this->connection->transaction(function () use ($entry, $embedding): MemoryEntry {
            $persisted = $this->inner->put($entry);

            if ($embedding !== null) {
                $this->index->upsert($persisted->scope, $persisted->scopeId, $persisted->key, $embedding);
            } else {
                // The value is not (or no longer) embeddable; drop any stale
                // embedding so a later semantic search can't surface it.
                $this->index->forget($persisted->scope, $persisted->scopeId, $persisted->key);
            }

            return $persisted;
        });
    }

    public function get(MemoryScope $scope, string $scopeId, string $key): ?MemoryEntry
    {
        return $this->inner->get($scope, $scopeId, $key);
    }

    public function forget(MemoryScope $scope, string $scopeId, string $key): bool
    {
        return $this->connection->transaction(function () use ($scope, $scopeId, $key): bool {
            $deleted = $this->inner->forget($scope, $scopeId, $key);

            $this->index->forget($scope, $scopeId, $key);

            return $deleted;
        });
    }

    public function all(MemoryScope $scope, string $scopeId): array
    {
        return $this->inner->all($scope, $scopeId);
    }

    /**
     * @return array<int, float>|null
     */
    private function embed(string $text, MemoryEntry $entry): ?array
    {
        try {
            return $this->embedder->embed($text);
        } catch (Throwable $exception) {
            if ($this->failOnEmbeddingError) {
                throw $exception instanceof EmbeddingFailedException
                    ? $exception
                    : new EmbeddingFailedException(
                        'Failed to embed memory entry ['.$entry->key.']: '.$exception->getMessage(),
                        previous: $exception,
                    );
            }

            $this->logger->warning('swarm-memory-vector: embedding failed; storing entry without a vector.', [
                'scope' => $entry->scope->value,
                'key' => $entry->key,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
