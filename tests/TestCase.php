<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Tests;

use BuiltByBerry\LaravelSwarm\SwarmServiceProvider;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\SwarmMemoryVectorServiceProvider;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Fixtures\FakeSwarm;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support\HashEmbedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * The embedding width used across the test suite. Small and fixed so the
     * deterministic {@see HashEmbedder} and the scan index round-trip cleanly.
     */
    public const DIMENSIONS = 16;

    /**
     * The run id every test writes Run-scoped memory under. Seeded into
     * swarm_run_histories so the run_id foreign key is satisfied, exactly as a
     * live run would be before its agents write memory.
     */
    protected const RUN_ID = 'run-1';

    protected function setUp(): void
    {
        parent::setUp();

        // Swap the real Laravel AI embedder for a deterministic, network-free
        // one. The store/index/reader resolve Embedder lazily, so binding it
        // here (after the providers register) takes effect on first use.
        $this->app->instance(Embedder::class, new HashEmbedder(self::DIMENSIONS));

        $this->seedRun(self::RUN_ID, FakeSwarm::class);
    }

    /**
     * Insert a minimal parent run-history row so Run-scoped memory writes
     * satisfy the swarm_memories.run_id foreign key (enforced on PostgreSQL,
     * and now on sqlite too). This mirrors production: the runner records the
     * run before any agent writes memory.
     */
    protected function seedRun(string $runId, string $swarmClass): void
    {
        DB::table('swarm_run_histories')->insert([
            'run_id' => $runId,
            'swarm_class' => $swarmClass,
            'topology' => 'sequential',
            'status' => 'running',
            'context' => json_encode([]),
            'metadata' => json_encode([]),
            'steps' => json_encode([]),
            'usage' => json_encode([]),
            'artifacts' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            SwarmServiceProvider::class,
            SwarmMemoryVectorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');

        $this->configureDatabase($app);

        // Memory persists to the database so the vector store decorates the
        // core DatabaseMemoryStore.
        $app['config']->set('swarm.persistence.driver', 'database');

        $app['config']->set('swarm-memory-vector.embedding.dimensions', self::DIMENSIONS);
    }

    /**
     * Use the CI-provided PostgreSQL connection when present (exercising the
     * native pgvector path), otherwise an in-memory sqlite database (the
     * portable scan path).
     */
    private function configureDatabase($app): void
    {
        if (env('DB_CONNECTION') === 'pgsql') {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'swarm_test'),
                'username' => env('DB_USERNAME', 'swarm'),
                'password' => env('DB_PASSWORD', 'swarm'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);

            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // On, so sqlite enforces the run_id foreign key just like PostgreSQL
            // does; the run-history row is seeded in setUp().
            'foreign_key_constraints' => true,
        ]);
    }
}
