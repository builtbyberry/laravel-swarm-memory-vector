<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Fixtures\FakeSwarm;

afterEach(function () {
    ActiveRunContext::flush();
});

function enterRun(string $runId = 'run-1'): void
{
    ActiveRunContext::enter($runId, FakeSwarm::class, RunContext::fake(['run_id' => $runId, 'input' => 'go']));
}

function reader(): VectorMemoryReader
{
    return app(VectorMemoryReader::class);
}

test('it degrades gracefully outside a swarm run', function () {
    expect(reader()->search(MemoryScope::Run, 'anything'))
        ->toBe('Memory is not available outside an active swarm run.');
});

test('it reports no memory when the scope is empty', function () {
    enterRun();

    expect(reader()->search(MemoryScope::Run, 'anything'))->toBe('No memory found.');
});

test('it ranks semantically closer entries first', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'rocket', 'the rocket launch schedule and payload');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'lunch', 'best tacos in the whole town');
    enterRun();

    $output = reader()->search(MemoryScope::Run, 'rocket launch');

    expect($output)->toContain('rocket:');
    expect(explode("\n", $output)[0])->toStartWith('rocket:');
});

test('it formats results as key: value lines like the Recall tool', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'launch plan');
    enterRun();

    expect(reader()->search(MemoryScope::Run, 'launch plan'))->toBe('topic: launch plan');
});

test('it caps the number of results at the limit', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'one', 'launch launch launch');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'two', 'launch launch plan');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'three', 'launch and go');
    enterRun();

    $output = reader()->search(MemoryScope::Run, 'launch', limit: 2);

    expect(explode("\n", $output))->toHaveCount(2);
});

test('it never surfaces an entry the propagation policy withholds', function () {
    // The default policy propagates only the Run scope. A Swarm-scoped entry
    // must not be reachable through vector search, exactly as with Recall.
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeSwarm::class, 'shared', 'team state and secrets');
    enterRun();

    expect(reader()->search(MemoryScope::Swarm, 'team state'))->toBe('No memory found.');
});

test('it does not surface non-string entries that were never embedded', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'count', 42);
    enterRun();

    expect(reader()->search(MemoryScope::Run, 'count'))->toBe('No memory found.');
});
