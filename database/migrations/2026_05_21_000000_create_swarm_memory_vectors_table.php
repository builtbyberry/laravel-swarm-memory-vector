<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the companion embedding table: one embedding per memory entry, keyed
 * by the same `(scope, scope_id, key)` tuple as `swarm_memories`.
 *
 * The embedding column type is driver-dependent. On PostgreSQL it is a native
 * `vector(N)` column (the `vector` extension is enabled first) indexed with
 * HNSW for cosine distance, driving the fast `pgvector` search path. On every
 * other driver it is a `text` column holding the JSON-encoded vector, ranked in
 * PHP by the portable `scan` path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connectionName = config('swarm-memory-vector.connection');
        $table = (string) config('swarm-memory-vector.table', 'swarm_memory_vectors');
        $dimensions = (int) config('swarm-memory-vector.embedding.dimensions', 1024);

        $schema = Schema::connection($connectionName);
        $driver = $schema->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $schema->getConnection()->statement('create extension if not exists vector');
        }

        $schema->create($table, function (Blueprint $blueprint) use ($driver, $dimensions): void {
            $blueprint->id();
            $blueprint->string('scope');
            $blueprint->string('scope_id');
            $blueprint->string('key');

            if ($driver === 'pgsql') {
                $blueprint->vector('embedding', $dimensions);
            } else {
                $blueprint->text('embedding');
            }

            $blueprint->unsignedInteger('dimensions');
            $blueprint->timestamps();

            $blueprint->unique(['scope', 'scope_id', 'key'], 'swarm_memory_vectors_scope_scope_id_key_unique');
            $blueprint->index(['scope', 'scope_id'], 'swarm_memory_vectors_scope_scope_id_index');
        });

        if ($driver === 'pgsql') {
            $schema->getConnection()->statement(
                "create index if not exists {$table}_embedding_hnsw_index "
                ."on {$table} using hnsw (embedding vector_cosine_ops)"
            );
        }
    }

    public function down(): void
    {
        Schema::connection(config('swarm-memory-vector.connection'))
            ->dropIfExists((string) config('swarm-memory-vector.table', 'swarm_memory_vectors'));
    }
};
