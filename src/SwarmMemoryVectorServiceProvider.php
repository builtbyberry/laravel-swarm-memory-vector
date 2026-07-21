<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector;

use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Memory\DatabaseMemoryStore;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\Embedding\LaravelAiEmbedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Index\PgVectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Index\ScanVectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Memory\SwarmVectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\Memory\VectorMemoryStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Auto-discovered package provider.
 *
 * Registers the embedding, index, and reader services, loads the vector table
 * migration, and — when enabled — rebinds the core `MemoryStore` contract to
 * the vector-backed store. The rebind is deferred to a framework `booted`
 * callback so it always lands after the core provider's own registration,
 * regardless of provider order, and so the core `RedactingMemoryStore`
 * decorator still wraps the result (redaction stays the outermost layer, the
 * vector store never sees a pre-redaction value).
 */
class SwarmMemoryVectorServiceProvider extends PackageServiceProvider
{
    public static string $name = 'swarm-memory-vector';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('builtbyberry/laravel-swarm-memory-vector');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Embedder::class, static function (Application $app): Embedder {
            /** @var Config $config */
            $config = $app->make('config');

            return new LaravelAiEmbedder(
                provider: $config->get('swarm-memory-vector.embedding.provider'),
                model: $config->get('swarm-memory-vector.embedding.model'),
                dimensions: (int) $config->get('swarm-memory-vector.embedding.dimensions', 1024),
            );
        });

        $this->app->singleton(VectorIndex::class, static function (Application $app): VectorIndex {
            /** @var Config $config */
            $config = $app->make('config');

            $connection = $app->make('db')->connection($config->get('swarm-memory-vector.connection'));
            $table = (string) $config->get('swarm-memory-vector.table', 'swarm_memory_vectors');

            $minSimilarity = $config->get('swarm-memory-vector.search.min_similarity');
            $minSimilarity = $minSimilarity !== null ? (float) $minSimilarity : null;

            $driver = $config->get('swarm-memory-vector.driver')
                ?? ($connection->getDriverName() === 'pgsql' ? 'pgvector' : 'scan');

            return $driver === 'pgvector'
                ? new PgVectorIndex($connection, $table, $minSimilarity)
                : new ScanVectorIndex($connection, $table, $minSimilarity);
        });

        $this->app->singleton(VectorMemoryReader::class, static function (Application $app): VectorMemoryReader {
            /** @var Config $config */
            $config = $app->make('config');

            return new SwarmVectorMemoryReader(
                index: $app->make(VectorIndex::class),
                embedder: $app->make(Embedder::class),
                defaultLimit: (int) $config->get('swarm-memory-vector.search.default_limit', 5),
            );
        });
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes(
            [__DIR__.'/../database/migrations' => database_path('migrations')],
            'swarm-memory-vector-migrations',
        );

        $this->app->booted(function (): void {
            $this->registerVectorStore();
        });
    }

    /**
     * Rebind the core memory store to the vector-backed decorator. The core
     * provider's `extend(MemoryStore::class, ...)` redaction wrapper still runs
     * on resolution, yielding `Redacting(Vector(Database))`.
     */
    private function registerVectorStore(): void
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        if (! (bool) $config->get('swarm-memory-vector.enabled', true)) {
            return;
        }

        $this->app->singleton(MemoryStore::class, static function (Application $app): MemoryStore {
            /** @var Config $config */
            $config = $app->make('config');

            return new VectorMemoryStore(
                inner: $app->make(DatabaseMemoryStore::class),
                index: $app->make(VectorIndex::class),
                embedder: $app->make(Embedder::class),
                connection: $app->make('db')->connection($config->get('swarm-memory-vector.connection')),
                failOnEmbeddingError: $config->get('swarm-memory-vector.on_embedding_failure') === 'throw',
                logger: $app->make(LoggerInterface::class),
            );
        });
    }
}
