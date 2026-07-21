# Changelog

All notable changes to `builtbyberry/laravel-swarm-memory-vector` are documented here.

## v0.1.0 - 2026-07-20

Initial release: vector-backed semantic recall for Laravel Swarm memory.

### Added

- `VectorMemoryStore` — a `MemoryStore` decorator that embeds every string-valued
  memory write and indexes it for semantic recall, while delegating canonical
  persistence to the core database store. The memory row and its embedding
  commit in a single transaction; the embedding provider call is made before
  the transaction opens.
- `VectorMemoryReader` (contract) and `SwarmVectorMemoryReader` — the public
  surface the core `make:memory-tool --vector` generator wires into
  `semanticRecall()`. Ranks only the entries the active swarm's propagation
  policy permits an agent to see, so a vector hit can never surface withheld
  memory.
- Two index drivers behind a `VectorIndex` contract: `PgVectorIndex` (native
  pgvector `vector(N)` column, `<=>` cosine distance, HNSW index) and
  `ScanVectorIndex` (portable JSON + in-PHP cosine, for sqlite/mysql).
- `Embedder` contract with a `LaravelAiEmbedder` default backed by Laravel AI
  embeddings, with dimension validation.
- Publishable config (`swarm-memory-vector`) and a driver-aware migration that
  creates the `swarm_memory_vectors` table.
- Graceful embedding-failure policy (`store_without_vector` by default, or
  `throw`).

### Requires

- PHP 8.5+, `builtbyberry/laravel-swarm` ^0.23, `laravel/ai` ^0.9.
