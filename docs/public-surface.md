# Public Surface

The stable, supported API of `builtbyberry/laravel-swarm-memory-vector`. Everything else is internal and may change without a major bump.

## Contracts

| Symbol | Kind | Notes |
|---|---|---|
| `Contracts\VectorMemoryReader` | interface | `search(MemoryScope $scope, string $query, int $limit = 5): string`. Resolved by the core `make:memory-tool --vector` stub. **FQN and signature are a contract with core.** |
| `Contracts\Embedder` | interface | `embed`, `embedBatch`, `dimensions`. Bind your own to swap the embedding source. |
| `Contracts\VectorIndex` | interface | `upsert`, `forget`, `rank`. Bind your own to swap the similarity backend. |

## Bindings

| Abstract | Default concrete | Scope |
|---|---|---|
| `MemoryStore` (core) | `Memory\VectorMemoryStore` wrapping `DatabaseMemoryStore` | singleton, rebound when `enabled` |
| `Contracts\VectorMemoryReader` | `Memory\SwarmVectorMemoryReader` | singleton |
| `Contracts\Embedder` | `Embedding\LaravelAiEmbedder` | singleton |
| `Contracts\VectorIndex` | `Index\PgVectorIndex` or `Index\ScanVectorIndex` (by driver) | singleton |

## Config

`config/swarm-memory-vector.php` — see the README configuration table. Publish tag: `swarm-memory-vector-config`. Migration publish tag: `swarm-memory-vector-migrations`.

## Schema

`swarm_memory_vectors` — one embedding per memory entry, unique on `(scope, scope_id, key)`. The `embedding` column is `vector(N)` on PostgreSQL (HNSW cosine index) and `text` (JSON) elsewhere.

## Guarantees

- The vector reader never surfaces an entry the active swarm's propagation policy withholds.
- Enabling the package does not change canonical memory persistence, exact-key recall, redaction, or replay — the embedding is additive.
- A memory write and its embedding commit atomically.
