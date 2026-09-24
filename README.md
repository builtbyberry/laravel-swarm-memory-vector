# Laravel Swarm Memory Vector

Vector-backed semantic recall for [Laravel Swarm](https://github.com/builtbyberry/laravel-swarm) memory. This companion package implements the core `MemoryStore` contract, transparently embedding every memory write and letting agents recall memory by meaning — "what did we learn about the rocket?" — instead of by exact key. Semantic search runs natively on PostgreSQL with [pgvector](https://github.com/pgvector/pgvector), with a portable fallback that works on any database.

> **Semantic search is a ranking layer, never a leak.** The vector reader only ever ranks the exact set of entries the active swarm's propagation policy already permits an agent to see. A vector hit can never surface memory that the standard `recall` tool would withhold.

## Requirements

- PHP 8.4+
- [`builtbyberry/laravel-swarm`](https://github.com/builtbyberry/laravel-swarm) ^0.27
- [`laravel/ai`](https://github.com/laravel/ai) ^1.0 (for embeddings)
- For the native `pgvector` driver: a PostgreSQL connection with the `vector` extension available

Version 0.2.0 adopts Laravel AI 1.x and core 0.27. Applications on older core/AI
lines must stay on a compatible 0.1.x companion release until they upgrade both.
See [UPGRADING.md](UPGRADING.md). The embedding API remains text-only.

The v0.2.0 compatibility work was validated against the exact core 0.27
candidate with minimum/current official AI 1.x on PHP 8.4/8.5 scan and PHP 8.4
real PostgreSQL/pgvector. Candidate checks used temporary CI metadata and are
historical prepublication evidence. A fresh Packagist-only consumer installation
remains a separate shipping gate. See the
[compatibility evidence](docs/ai-1-compatibility-evidence.md).

## Installation

```bash
composer require builtbyberry/laravel-swarm-memory-vector
```

Publish the config and run the migration:

```bash
php artisan vendor:publish --tag=swarm-memory-vector-config
php artisan migrate
```

The migration creates a `swarm_memory_vectors` table. On PostgreSQL it enables the `vector` extension and creates a native `vector(N)` column with an HNSW cosine index; on any other database it stores embeddings as JSON for the portable scan driver.

### Configure an embedding provider

Embeddings are generated through Laravel AI. Point it at your embedding provider of choice — for example [Voyage AI](https://www.voyageai.com):

```env
VOYAGEAI_API_KEY=your-key
SWARM_MEMORY_VECTOR_PROVIDER=voyageai
SWARM_MEMORY_VECTOR_DIMENSIONS=1024
```

`SWARM_MEMORY_VECTOR_DIMENSIONS` **must** match the vector width your provider/model returns — it fixes the width of the `vector(N)` column. Voyage's `voyage-4` returns 1024; OpenAI's `text-embedding-3-small` returns 1536. A mismatch fails loudly rather than corrupting the index.

Laravel AI's HTTP-backed providers do not require an additional provider SDK.
Bedrock requires the optional `aws/aws-sdk-php` package; selecting Bedrock without
it gives installation guidance. Follow the installed Laravel AI package's
optional-dependency constraints and use a supported, security-patched SDK release.
This companion does not require AWS SDK or Laravel MCP. Native HTTP-wire tests
cover OpenAI and Voyage; they do not establish AWS transport compatibility.

## How it works

Once installed, the package rebinds the Swarm `MemoryStore` to a decorator around the core database store:

- **Writes** still land in `swarm_memories` exactly as before — same rows, timestamps, lifecycle events, and `run_id` cascade. Alongside each string-valued write, the decorator generates an embedding and upserts it into `swarm_memory_vectors`. The memory row and its embedding commit in a single transaction; the embedding provider call happens *before* the transaction opens, so a slow network round-trip never holds a database transaction open.
- **Redaction stays outermost.** The core `RedactingMemoryStore` wraps this store, so the text that gets embedded is the same capture-policy-redacted text that gets persisted — never a pre-redaction value.
- **Reads** are unchanged. Exact-key recall, propagation, and replay all behave identically; the embedding is purely additive.

Non-string values (arrays, numbers, booleans) are persisted normally but not embedded — they remain fully available through the exact-key `recall` tool.

## Semantic recall in an agent

Scaffold a vector-aware memory tool with the core generator (it detects this companion via Composer):

```bash
php artisan make:memory-tool SemanticRecall --vector
```

Wire the generated tool's `semanticRecall()` to this package's reader:

```php
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use Illuminate\Container\Container;

protected function semanticRecall(string $query, MemoryScope $scope): string
{
    return Container::getInstance()
        ->make(VectorMemoryReader::class)
        ->search($scope, $query, limit: 5);
}
```

Drop the tool into any `laravel/ai` agent's `tools()` array. When the model calls it with a free-text `query`, it gets back the most semantically similar memory entries, formatted exactly like the core `recall` tool (`key: value`, one per line). The scope id is resolved from the active run — never accepted from the model.

## Configuration

All keys live in `config/swarm-memory-vector.php`:

| Key | Env | Default | Purpose |
|---|---|---|---|
| `enabled` | `SWARM_MEMORY_VECTOR_ENABLED` | `true` | Rebind the memory store to the vector-backed decorator. |
| `connection` | `SWARM_MEMORY_VECTOR_CONNECTION` | default | Connection holding the vector table (must match the memory connection). |
| `driver` | `SWARM_MEMORY_VECTOR_DRIVER` | auto | `pgvector`, `scan`, or null to auto-detect (pgvector on pgsql). |
| `table` | `SWARM_MEMORY_VECTOR_TABLE` | `swarm_memory_vectors` | The embedding table. |
| `embedding.provider` | `SWARM_MEMORY_VECTOR_PROVIDER` | Laravel AI default | Embedding provider. |
| `embedding.model` | `SWARM_MEMORY_VECTOR_MODEL` | provider default | Embedding model. |
| `embedding.dimensions` | `SWARM_MEMORY_VECTOR_DIMENSIONS` | `1024` | Vector width; must match the provider/model. |
| `search.default_limit` | `SWARM_MEMORY_VECTOR_LIMIT` | `5` | Results returned when the caller omits a limit. |
| `search.min_similarity` | `SWARM_MEMORY_VECTOR_MIN_SIMILARITY` | null | Drop hits below this cosine score. |
| `on_embedding_failure` | `SWARM_MEMORY_VECTOR_ON_EMBEDDING_FAILURE` | `store_without_vector` | `store_without_vector` keeps memory durable when embedding fails; `throw` aborts the write. |

## Drivers

- **`pgvector`** — native `vector(N)` column ranked with PostgreSQL's `<=>` cosine-distance operator over an HNSW index. The headline path; scales to large memory.
- **`scan`** — stores embeddings as JSON and ranks with an in-PHP cosine similarity. Works on sqlite and mysql. Because ranking is always scoped to the small, already-authorized candidate set, the scan stays cheap.

## Testing

```bash
composer test      # Pest
composer analyse   # PHPStan (level 8)
composer lint      # Pint
```

The suite runs against sqlite (scan driver) locally and additionally against a real pgvector-enabled PostgreSQL in CI.

## License

MIT © [Daniel Berry / Built by Berry](https://builtbyberry.com)
