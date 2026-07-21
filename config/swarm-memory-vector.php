<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver Activation
    |--------------------------------------------------------------------------
    |
    | When enabled, this package rebinds the Laravel Swarm `MemoryStore`
    | contract to the vector-backed store, so every memory write is
    | transparently embedded and indexed for semantic recall. The store
    | decorates the core database store — memory still lives in
    | `swarm_memories` exactly as before; the embedding is written alongside
    | it in this package's own table. Set to false to install the package's
    | reader/tooling without taking over memory persistence.
    |
    */

    'enabled' => (bool) env('SWARM_MEMORY_VECTOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection that holds the vector table. Leave null to use the
    | application's default connection. This MUST be the same connection the
    | core memory store writes to, so that a memory write and its embedding
    | write commit atomically in a single transaction. For the native
    | `pgvector` driver this must be a PostgreSQL connection with the
    | `vector` extension available.
    |
    */

    'connection' => env('SWARM_MEMORY_VECTOR_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Index Driver
    |--------------------------------------------------------------------------
    |
    | "pgvector" uses a native `vector` column and PostgreSQL's `<=>` cosine
    | distance operator (HNSW indexed) for similarity search. "scan" is a
    | portable fallback that stores embeddings as JSON and ranks in PHP —
    | correct on any database (sqlite, mysql), just slower at scale. Leave
    | null to auto-detect: pgvector on a pgsql connection, scan otherwise.
    |
    */

    'driver' => env('SWARM_MEMORY_VECTOR_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Vector Table
    |--------------------------------------------------------------------------
    |
    | The table that stores one embedding per memory entry, keyed by the same
    | (scope, scope_id, key) tuple as `swarm_memories`.
    |
    */

    'table' => env('SWARM_MEMORY_VECTOR_TABLE', 'swarm_memory_vectors'),

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Embeddings are generated through Laravel AI (`laravel/ai`). Leave
    | `provider` and `model` null to use the `ai.default_for_embeddings`
    | provider and its default model. `dimensions` MUST match the vector
    | length your provider/model returns — it fixes the width of the
    | `vector(N)` column at migration time. Voyage's `voyage-4` returns 1024;
    | OpenAI's `text-embedding-3-small` returns 1536.
    |
    */

    'embedding' => [
        'provider' => env('SWARM_MEMORY_VECTOR_PROVIDER'),
        'model' => env('SWARM_MEMORY_VECTOR_MODEL'),
        'dimensions' => (int) env('SWARM_MEMORY_VECTOR_DIMENSIONS', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | `default_limit` is the number of results the vector reader returns when
    | the caller does not specify one. `min_similarity` (null or a cosine
    | score between 0 and 1) drops hits below the threshold so weak matches
    | never reach the model; leave null to return the top matches regardless
    | of score.
    |
    */

    'search' => [
        'default_limit' => (int) env('SWARM_MEMORY_VECTOR_LIMIT', 5),
        'min_similarity' => env('SWARM_MEMORY_VECTOR_MIN_SIMILARITY') !== null
            ? (float) env('SWARM_MEMORY_VECTOR_MIN_SIMILARITY')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Failure Policy
    |--------------------------------------------------------------------------
    |
    | How to behave when the embedding provider is unavailable or errors on a
    | write. "store_without_vector" (default) keeps memory durable: the entry
    | is persisted to `swarm_memories` as normal and simply is not indexed for
    | semantic recall (a warning is logged). "throw" makes the memory write
    | fail hard when its embedding cannot be produced.
    |
    */

    'on_embedding_failure' => env('SWARM_MEMORY_VECTOR_ON_EMBEDDING_FAILURE', 'store_without_vector'),

];
