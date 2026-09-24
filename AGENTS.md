# Laravel Swarm Memory Vector — Agent Context

Companion package for [Laravel Swarm](https://github.com/builtbyberry/laravel-swarm). Adds vector-backed semantic recall by implementing the core `MemoryStore` contract. Standalone repo under the `builtbyberry/` org; own release cadence and CI.

## Commands

```bash
composer test      # Pest (tests/Feature)
composer analyse   # PHPStan level 8
composer lint      # Pint (--test); `composer format` to fix
```

## Architecture invariants — do not break these

- **Redaction stays outermost.** The store is installed by *rebinding* `MemoryStore` (never `Container::instance`, never `extend`), so the core `RedactingMemoryStore` decorator wraps the result: `Redacting(Vector(Database))`. The vector store must only ever see capture-policy-redacted values. The rebind is deferred to an `app->booted()` callback so it lands after the core provider registers, regardless of provider order.
- **The reader ranks, it does not gate.** `SwarmVectorMemoryReader` resolves the authorized entry set via the core `AgentVisibleMemoryView` (exactly as the `Recall` tool does), then asks the `VectorIndex` only to *order* that set. Never search the whole table and filter afterward — a policy-withheld entry must never be a candidate. Scope ids come from the resolved entries, never from the caller.
- **Transaction boundary.** `VectorMemoryStore::put()`/`forget()` coordinate two tables on one connection and commit atomically. The embedding provider call (slow, fallible) happens *before* the transaction opens — never inside it.
- **The vector index is a cache of `swarm_memories`, not a source of truth.** Canonical memory persistence is delegated to the core `DatabaseMemoryStore`. The `swarm_memory_vectors` table only holds embeddings, keyed by the same `(scope, scope_id, key)` tuple.
- **Public surface for the generator.** The core `make:memory-tool --vector` stub resolves `BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader` and calls `search(MemoryScope $scope, string $query, int $limit = 5): string`. That FQN and signature are a contract with core — do not rename them.

## Layout

- `src/Contracts/` — `Embedder`, `VectorIndex`, `VectorMemoryReader`.
- `src/Memory/` — `VectorMemoryStore` (the store decorator), `SwarmVectorMemoryReader`.
- `src/Index/` — `AbstractVectorIndex`, `PgVectorIndex`, `ScanVectorIndex`.
- `src/Embedding/` — `LaravelAiEmbedder`.
- `database/migrations/` — driver-aware `swarm_memory_vectors` table.

## Testing notes

- Tests bind a deterministic `HashEmbedder` (no network). Reader tests enter an `ActiveRunContext` frame with a `FakeSwarm` fixture and `RunContext::fake()`.
- SQLite enforces foreign keys; `tests/TestCase.php` seeds the parent run-history row for Run-scoped memory.
- CI runs PHP 8.4 and 8.5 scan lanes for the pinned core 0.27 candidate with
  minimum/current official AI 1.x dependencies. Native pgvector runs on a real
  PostgreSQL service on PHP 8.4 for both dependency sets. All six lanes fail on
  skipped tests. Version 0.2.0 drops incompatible old core/AI ranges; candidate
  CI is not published-installability proof.
