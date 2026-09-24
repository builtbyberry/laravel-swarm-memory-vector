<?php

declare(strict_types=1);

use Aws\BedrockRuntime\BedrockRuntimeClient;
use BuiltByBerry\LaravelSwarm\Audit\CaptureDecision;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryCapturePolicy;
use BuiltByBerry\LaravelSwarm\Contracts\MemoryStore;
use BuiltByBerry\LaravelSwarm\Contracts\SwarmMemory;
use BuiltByBerry\LaravelSwarm\Enums\MemoryScope;
use BuiltByBerry\LaravelSwarm\Memory\RedactingMemoryStore;
use BuiltByBerry\LaravelSwarm\Support\ActiveRunContext;
use BuiltByBerry\LaravelSwarm\Support\RunContext;
use BuiltByBerry\LaravelSwarm\Support\SwarmCapture;
use BuiltByBerry\LaravelSwarmMemoryVector\Contracts\VectorMemoryReader;
use BuiltByBerry\LaravelSwarmMemoryVector\Embedding\LaravelAiEmbedder;
use BuiltByBerry\LaravelSwarmMemoryVector\Exceptions\EmbeddingFailedException;
use BuiltByBerry\LaravelSwarmMemoryVector\Memory\VectorMemoryStore;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Fixtures\FakeSwarm;
use BuiltByBerry\LaravelSwarmMemoryVector\Tests\Support\NativeEmbeddingWire as Wire;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    ActiveRunContext::flush();
});

function nativeVectorEnterRun(): void
{
    ActiveRunContext::enter('run-1', FakeSwarm::class, RunContext::fake(['run_id' => 'run-1', 'input' => 'recall']));
}

test('native HTTP embeddings persist query rank update and forget through the public consumer', function () {
    Wire::bind();
    $baselineTransaction = DB::connection()->transactionLevel();
    Http::fake([Wire::OPENAI_URL => function (Request $request) use ($baselineTransaction) {
        expect(DB::connection()->transactionLevel())->toBe($baselineTransaction);
        $text = $request['input'][0];
        $vector = match ($text) {
            'rocket launch' => Wire::vector(0),
            'taco lunch', 'new lunch' => Wire::vector(1),
            'query the launch' => [0.9, 0.1, ...array_fill(0, 14, 0.0)],
            default => throw new LogicException('Unexpected wire input: '.$text),
        };

        return Http::response(Wire::response([$vector]));
    }]);

    $memory = app(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'rocket', 'rocket launch');
    $memory->put(MemoryScope::Run, 'run-1', 'lunch', 'taco lunch');
    expect(DB::table('swarm_memories')->count())->toBe(2);
    expect(DB::table('swarm_memory_vectors')->count())->toBe(2);
    nativeVectorEnterRun();
    $reader = app(VectorMemoryReader::class);
    expect($reader->search(MemoryScope::Run, '  query the launch  ', 2))->toBe("rocket: rocket launch\nlunch: taco lunch");

    $memory->put(MemoryScope::Run, 'run-1', 'rocket', 'new lunch');
    expect($memory->get(MemoryScope::Run, 'run-1', 'rocket'))->toBe('new lunch');
    expect(DB::table('swarm_memory_vectors')->where('key', 'rocket')->count())->toBe(1);
    // Inspect the index payload as well as the canonical value after update.
    $stored = DB::table('swarm_memory_vectors')->where('key', 'rocket')->value('embedding');
    expect(array_map('floatval', json_decode($stored, true, flags: JSON_THROW_ON_ERROR)))->toBe(Wire::vector(1));
    $memory->forget(MemoryScope::Run, 'run-1', 'rocket');
    expect(DB::table('swarm_memories')->where('key', 'rocket')->exists())->toBeFalse();
    expect(DB::table('swarm_memory_vectors')->where('key', 'rocket')->exists())->toBeFalse();
    expect($reader->search(MemoryScope::Run, 'query the launch'))->toBe('lunch: taco lunch');
    Http::assertSentCount(5);
});

test('the outer capture policy redacts native outgoing text and persisted memory', function () {
    Wire::bind();
    $policy = Mockery::mock(MemoryCapturePolicy::class);
    $policy->shouldReceive('memory')->once()->with(MemoryScope::Run, 'secret')->andReturn(CaptureDecision::Redact);
    app()->instance(MemoryCapturePolicy::class, $policy);
    Http::fake([Wire::OPENAI_URL => Http::response(Wire::response([Wire::vector(0)]))]);

    $store = app(MemoryStore::class);
    expect($store)->toBeInstanceOf(RedactingMemoryStore::class);
    expect($store->inner())->toBeInstanceOf(VectorMemoryStore::class);
    app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'secret', 'must-never-reach-provider');
    Http::assertSent(fn (Request $request): bool => $request['input'] === [SwarmCapture::REDACTED]);
    Http::assertSentCount(1);
    expect(app(SwarmMemory::class)->get(MemoryScope::Run, 'run-1', 'secret'))->toBe(SwarmCapture::REDACTED);
    expect(DB::table('swarm_memory_vectors')->count())->toBe(1);
});

test('native provider failure preserves default storage and removes an old vector', function () {
    Wire::bind();
    Http::fake([Wire::OPENAI_URL => Http::sequence()
        ->push(Wire::response([Wire::vector(0)]))
        ->push(['error' => ['message' => 'provider failed']], 400)
        ->push(['error' => ['message' => 'provider failed']], 400),
    ]);
    $memory = app(SwarmMemory::class);
    $memory->put(MemoryScope::Run, 'run-1', 'topic', 'first');
    expect(DB::table('swarm_memory_vectors')->count())->toBe(1);
    $memory->put(MemoryScope::Run, 'run-1', 'topic', 'second');
    expect($memory->get(MemoryScope::Run, 'run-1', 'topic'))->toBe('second');
    expect(DB::table('swarm_memory_vectors')->count())->toBe(0);
    nativeVectorEnterRun();
    expect(app(VectorMemoryReader::class)->search(MemoryScope::Run, 'query'))
        ->toBe('Semantic recall is temporarily unavailable (embedding failed).');
    Http::assertSentCount(3);
});

test('native provider failure under throw policy leaves both tables unchanged', function () {
    Wire::bind();
    config()->set('swarm-memory-vector.on_embedding_failure', 'throw');
    Http::fake([Wire::OPENAI_URL => Http::response(['error' => ['message' => 'provider failed']], 400)]);

    expect(fn () => app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'first'))
        ->toThrow(EmbeddingFailedException::class);
    expect(DB::table('swarm_memories')->count())->toBe(0);
    expect(DB::table('swarm_memory_vectors')->count())->toBe(0);
    Http::assertSentCount(1);
});

test('missing single-input native embedding results use the existing storage failure policy', function (array $payload) {
    Wire::bind();
    config()->set('swarm-memory-vector.on_embedding_failure', 'throw');
    Http::fake([Wire::OPENAI_URL => Http::response($payload)]);

    expect(fn () => app(SwarmMemory::class)->put(MemoryScope::Run, 'run-1', 'topic', 'first'))
        ->toThrow(EmbeddingFailedException::class);
    expect(DB::table('swarm_memories')->count())->toBe(0);
    expect(DB::table('swarm_memory_vectors')->count())->toBe(0);
})->with(['empty data' => [['data' => []]], 'missing data' => [[]]]);

test('optional provider SDKs remain absent and Bedrock gives actionable installation guidance', function () {
    Wire::configure();
    expect(InstalledVersions::isInstalled('aws/aws-sdk-php'))->toBeFalse();
    expect(InstalledVersions::isInstalled('laravel/mcp'))->toBeFalse();
    expect(class_exists(BedrockRuntimeClient::class))->toBeFalse();
    $embedder = new LaravelAiEmbedder('bedrock', 'amazon.titan-embed-text-v2:0', 16);

    expect(fn () => $embedder->embed('optional provider'))->toThrow(RuntimeException::class, 'composer require aws/aws-sdk-php');
    Http::assertNothingSent();
});

test('native AI metadata declares optional provider compatibility floors without requiring SDKs', function () {
    $native = json_decode(file_get_contents(InstalledVersions::getInstallPath('laravel/ai').'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($native['name'])->toBe('laravel/ai');
    expect($native['require'])->not->toHaveKey('aws/aws-sdk-php')->not->toHaveKey('laravel/mcp');
    expect($native['suggest'])->toHaveKeys(['aws/aws-sdk-php', 'laravel/mcp']);
    expect($native['conflict']['aws/aws-sdk-php'])->toBe('<3.369.1');
    expect($native['conflict']['laravel/mcp'])->toBe('<1.0');
    expect(Semver::satisfies('3.369.0', $native['conflict']['aws/aws-sdk-php']))->toBeTrue();
    expect(Semver::satisfies('3.369.1', $native['conflict']['aws/aws-sdk-php']))->toBeFalse();
    expect(Semver::satisfies('0.7.0', $native['conflict']['laravel/mcp']))->toBeTrue();
    expect(Semver::satisfies('1.0.0', $native['conflict']['laravel/mcp']))->toBeFalse();
});
