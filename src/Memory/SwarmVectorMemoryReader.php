<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Memory;

use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\AgentVisibleMemoryView;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Tools\Recall;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use Illuminate\Container\Container;
use ReflectionClass;
use Throwable;

/**
 * Default {@see VectorMemoryReader}.
 *
 * The design deliberately makes the vector index a *ranker*, never a gate. It
 * first resolves — exactly as the core `Recall` tool does — the set of entries
 * the active swarm's propagation policy permits this agent to see, via
 * {@see AgentVisibleMemoryView}. It then asks the index only to order that
 * authorized set by similarity to the query. A vector hit that the policy
 * would withhold can therefore never surface, because it is never a candidate
 * in the first place. Scope ids are taken from the resolved entries, never
 * from the caller.
 *
 * Output matches the core Recall tool: one `key: value` per line.
 */
final class SwarmVectorMemoryReader implements VectorMemoryReader
{
    public function __construct(
        private readonly VectorIndex $index,
        private readonly Embedder $embedder,
        private readonly int $defaultLimit,
    ) {}

    public function search(MemoryScope $scope, string $query, int $limit = 5): string
    {
        $query = trim($query);

        if ($query === '') {
            return 'No memory found.';
        }

        $limit = $limit > 0 ? $limit : $this->defaultLimit;

        $entries = $this->visibleEntries($scope);

        if ($entries === null) {
            return 'Memory is not available outside an active swarm run.';
        }

        if ($entries === []) {
            return 'No memory found.';
        }

        // Every entry in a single scope shares one scope id (the run's id, the
        // swarm class, etc.), so it is safe to read it off the authorized set
        // rather than re-deriving it from the run frame.
        $scopeId = $entries[0]->scopeId;

        $byKey = [];

        foreach ($entries as $entry) {
            $byKey[$entry->key] = $entry;
        }

        try {
            $queryVector = $this->embedder->embed($query);
        } catch (Throwable) {
            return 'Semantic recall is temporarily unavailable (embedding failed).';
        }

        $ranked = $this->index->rank($queryVector, $scope, $scopeId, array_keys($byKey), $limit);

        $lines = [];

        foreach ($ranked as $key) {
            if (! isset($byKey[$key])) {
                continue;
            }

            $lines[] = $key.': '.$this->renderValue($byKey[$key]->value);
        }

        return $lines === [] ? 'No memory found.' : implode("\n", $lines);
    }

    /**
     * Gather the entries this agent may see in the requested scope, filtered
     * through the active swarm's propagation policy. Returns null when there is
     * no active run. Mirrors {@see Recall}.
     *
     * @return array<int, MemoryEntry>|null
     */
    private function visibleEntries(MemoryScope $scope): ?array
    {
        $record = ActiveRunContext::current();

        if ($record === null) {
            return null;
        }

        $swarm = $this->resolveSwarm($record->swarmClass);

        if ($swarm === null) {
            return null;
        }

        $view = Container::getInstance()->make(AgentVisibleMemoryView::class);

        $presented = $view->present($swarm, $record->context, null);

        return array_values(array_filter(
            $presented,
            static fn (MemoryEntry $entry): bool => $entry->scope === $scope,
        ));
    }

    /**
     * Reflect a constructor-less swarm instance purely so the visibility view
     * can read its class name and `#[PropagationPolicy]` attribute — mirrors
     * the core Recall tool.
     */
    private function resolveSwarm(string $swarmClass): ?Swarm
    {
        if (! is_a($swarmClass, Swarm::class, true)) {
            return null;
        }

        try {
            /** @var Swarm $swarm */
            $swarm = (new ReflectionClass($swarmClass))->newInstanceWithoutConstructor();

            return $swarm;
        } catch (Throwable) {
            return null;
        }
    }

    private function renderValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_null($value) => 'null',
            is_array($value) => $this->encodeArray($value),
            default => '[unreadable]',
        };
    }

    /**
     * @param  array<mixed>  $value
     */
    private function encodeArray(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[unreadable]';
        }
    }
}
