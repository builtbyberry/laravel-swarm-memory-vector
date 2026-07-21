<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Contracts;

use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;

/**
 * Answers a free-text query with the most semantically similar memory entries.
 *
 * This is the public surface the core `make:memory-tool --vector` generator
 * wires into a generated tool's `semanticRecall()`. The scope id is never
 * accepted from the caller — it is resolved from the ambient active run, and
 * results are filtered through the active swarm's propagation policy — so a
 * vector hit can only ever surface an entry the agent is already permitted to
 * read via the standard `recall` tool.
 */
interface VectorMemoryReader
{
    /**
     * Return the memory entries in the given scope most similar to the query,
     * formatted as a model-facing string (one `key: value` per line, matching
     * the core Recall tool), or a short status string when there is nothing to
     * return or memory is unavailable outside a run.
     */
    public function search(MemoryScope $scope, string $query, int $limit = 5): string;
}
