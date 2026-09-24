<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarm\Contracts\MemoryPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\DefaultPropagationPolicy;
use BuiltByBerry\LaravelSwarm\Memory\MemoryEntry;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\Embedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorIndex;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\Memory\SwarmVectorMemoryReader;
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

test('the ranker receives only policy authorized entries and their resolved scope id', function () {
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'visible', 'allowed');
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'secret', 'withheld');
    $policy = Mockery::mock(MemoryPropagationPolicy::class);
    $policy->shouldReceive('scopes')->once()->andReturn([MemoryScope::Run]);
    $policy->shouldReceive('present')->once()->andReturnUsing(function (array $entries) {
        expect(array_column($entries, 'key'))->toContain('visible', 'secret');

        // A presented snapshot can supply the resolved address; the ranker
        // must use this address rather than reconstructing it from the frame.
        return [new MemoryEntry(MemoryScope::Run, 'resolved-scope', 'visible', 'allowed')];
    });
    app()->instance(DefaultPropagationPolicy::class, $policy);
    $embedder = Mockery::mock(Embedder::class);
    $embedder->shouldReceive('embed')->once()->with('query')->andReturn([1.0]);
    $index = Mockery::mock(VectorIndex::class);
    $index->shouldReceive('rank')->once()->with([1.0], MemoryScope::Run, 'resolved-scope', ['visible'], 2)
        ->andReturn(['secret', 'visible']);
    $reader = new SwarmVectorMemoryReader($index, $embedder, 5);
    enterRun();

    expect($reader->search(MemoryScope::Run, 'query', 2))->toBe('visible: allowed');
});

test('empty or policy withheld scopes never reach the embedder or ranker', function (MemoryScope $scope) {
    app(SwarmMemory::class)->put(MemoryScope::Swarm, FakeSwarm::class, 'secret', 'withheld');
    $embedder = Mockery::mock(Embedder::class);
    $embedder->shouldNotReceive('embed');
    $index = Mockery::mock(VectorIndex::class);
    $index->shouldNotReceive('rank');
    $reader = new SwarmVectorMemoryReader($index, $embedder, 5);
    enterRun();

    expect($reader->search($scope, 'query'))->toBe('No memory found.');
})->with(['empty Run scope' => MemoryScope::Run, 'withheld Swarm scope' => MemoryScope::Swarm]);
