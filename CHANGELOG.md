# Changelog

## Unreleased

### Changed

- Extend core compatibility through `^0.26` and Laravel AI through `^0.11.2`,
  preserving core `^0.20`–`^0.25` and AI `^0.9 || ^0.10.3`.
- Exercise the pinned core 0.26 candidate with minimum and current AI 0.11
  dependencies on PHP 8.4/8.5 scan and PHP 8.4 native PostgreSQL/pgvector,
  alongside the published 0.25 baseline, lowest dependencies, and explicit
  published core 0.20–0.24 scan lanes. Verify installed/locked dependency
  provenance with negative controls. Candidate metadata is temporary CI input;
  it does not establish published ecosystem installability. This retains old
  consumer ranges instead of forcing all applications onto core 0.26.

## v0.1.3 - 2026-09-03

### Changed

- Supports PHP `^8.4`, with CI covering PHP 8.4 and 8.5 against latest and
  lowest dependency sets. The vector-memory behavior and supported Laravel
  Swarm `^0.20` through `^0.25` range are unchanged.

## v0.1.2 - 2026-09-02

### Changed

- **Extended the verified Laravel Swarm range through `^0.25`.** The v0.25
  release keeps the same `MemoryStore` contract and Laravel AI `^0.10.3` line as
  v0.24, so the companion requires no integration-code change. Its full suite
  passes against the exact v0.25 release branch in addition to every supported
  published core line from v0.20 through v0.24.

## v0.1.1 - 2026-09-02

### Changed

- **Widened the `builtbyberry/laravel-swarm` constraint from `^0.23` to
  `^0.20 || ^0.21 || ^0.22 || ^0.23 || ^0.24`.** The single-minor pin meant
  Composer refused to install this package alongside any core release other than
  0.23 — including the next one — so a core upgrade silently made vector memory
  uninstallable rather than reporting an incompatibility.

  The companion's `laravel/ai` constraint is widened alongside core, from `^0.9`
  to `^0.9 || ^0.10.3`, because Laravel Swarm v0.24.0 requires Laravel AI
  v0.10.3 or newer in that minor line.

  The range is verified, not assumed: this package's suite (29 tests) was run
  against core v0.20.0, v0.21.0, v0.22.0, v0.23.0 and the published v0.24.0
  release, passing on all five. The floor is v0.20.0 because that is the earliest
  core release requiring Laravel AI v0.9; core v0.19.0 and earlier pin v0.8 and
  cannot co-resolve with this package's supported Laravel AI range.

  Nothing about the integration changed. This package decorates the `MemoryStore`
  contract, whose four methods are byte-identical across v0.20.0–v0.24.0, and it
  touches no core table directly.

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
