<?php

declare(strict_types=1);

namespace VectorCompatibility;

use Composer\Semver\Semver;

const CORE = 'builtbyberry/laravel-swarm';
const CANDIDATE_REF = '48ad4ef690363ca40ba7d3bd50e63e7fbe76ba4b';
const AI_MINIMUM_REF = '101c7ea33cd8569d82570f753fbf38e48b7d3d95';
const CORE_RANGE = '^0.27';
const AI_RANGE = '^1.0';
const LANES = ['adoption-minimum', 'adoption-current'];

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new \RuntimeException($message);
    }
}

function readJson(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function prepare(array $root, string $lane, ?array $candidate): array
{
    check(in_array($lane, LANES, true), 'Unknown compatibility lane.');
    check(($root['require'][CORE] ?? null) === CORE_RANGE, 'Keep the complete supported core range.');
    check(($root['require']['laravel/ai'] ?? null) === AI_RANGE, 'Keep the complete supported AI range.');
    check(! isset($root['repositories']) && ! isset($root['replace']), 'Production manifest must not override package provenance.');
    foreach ($root['require'] as $constraint) {
        check(! str_contains($constraint, ' as '), 'Production dependencies must not use version aliases.');
    }
    check(($candidate['name'] ?? null) === CORE, 'Expected the official core candidate manifest.');
    check(($candidate['require']['laravel/ai'] ?? null) === AI_RANGE, 'Unexpected candidate AI contract.');
    // This synthetic version is CI-only: the source and archive are immutable.
    $candidate['version'] = '0.27.0';
    $candidate['source'] = ['type' => 'git', 'url' => 'https://github.com/builtbyberry/laravel-swarm.git', 'reference' => CANDIDATE_REF];
    $candidate['dist'] = ['type' => 'zip', 'url' => 'https://api.github.com/repos/builtbyberry/laravel-swarm/zipball/'.CANDIDATE_REF, 'reference' => CANDIDATE_REF];
    unset($candidate['require-dev'], $candidate['scripts'], $candidate['repositories']);
    $root['repositories'] = [['type' => 'package', 'package' => $candidate]];
    $root['require'][CORE] = '0.27.0';
    $root['require']['laravel/ai'] = $lane === 'adoption-minimum' ? '1.0.0' : AI_RANGE;

    return $root;
}

function packages(array $packages): array
{
    return array_column($packages, null, 'name');
}

function verify(array $locked, array $installed, string $lane): array
{
    check(in_array($lane, LANES, true), 'Unknown compatibility lane.');
    $evidence = [];
    foreach ([CORE, 'laravel/ai', 'laravel/framework'] as $name) {
        $lock = $locked[$name] ?? [];
        $actual = $installed[$name] ?? [];
        foreach (['version', 'source', 'dist'] as $field) {
            check(isset($lock[$field]) && ($actual[$field] ?? null) === $lock[$field], "{$name}: installed {$field} differs from lock or is absent.");
        }
        $version = ltrim($actual['version'], 'v');
        check((bool) preg_match('/^\d+\.\d+\.\d+$/D', $version), "{$name}: expected a stable version.");
        $repository = $name;
        check(($actual['source']['type'] ?? null) === 'git', "{$name}: expected git source.");
        check(($actual['source']['url'] ?? null) === "https://github.com/{$repository}.git", "{$name}: expected official source.");
        $ref = $actual['source']['reference'] ?? '';
        check((bool) preg_match('/^[a-f0-9]{40}$/D', $ref), "{$name}: expected immutable source reference.");
        check(($actual['dist']['type'] ?? null) === 'zip'
            && ($actual['dist']['reference'] ?? null) === $ref
            && ($actual['dist']['url'] ?? null) === "https://api.github.com/repos/{$repository}/zipball/{$ref}", "{$name}: expected matching official archive.");
        if ($name === CORE) {
            check($version === '0.27.0', 'Wrong core version for lane.');
            check($ref === CANDIDATE_REF, 'Wrong core source for lane.');
        } elseif ($name === 'laravel/ai') {
            check(Semver::satisfies($version, AI_RANGE), 'Expected official stable AI ^1.0.');
            if ($lane === 'adoption-minimum') {
                check($version === '1.0.0' && $ref === AI_MINIMUM_REF, 'Expected exact official AI minimum.');
            }
        } elseif ($name === 'laravel/framework') {
            check(str_starts_with($version, '13.'), 'Expected Laravel 13.');
        }
        $evidence[] = "{$name} {$actual['version']} {$ref}";
    }

    check(Semver::satisfies($installed['laravel/ai']['version'], AI_RANGE), 'AI must satisfy the companion direct constraint.');
    $constraint = $locked[CORE]['require']['laravel/ai'] ?? null;
    check(is_string($constraint) && ($installed[CORE]['require']['laravel/ai'] ?? null) === $constraint, 'Installed core AI contract differs from lock or is absent.');
    check(Semver::satisfies($installed['laravel/ai']['version'], $constraint), 'AI does not satisfy the resolved core contract.');

    return $evidence;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $command = $argv[1] ?? '';
        $lane = $argv[2] ?? '';
        if ($command === 'prepare') {
            $root = prepare(readJson('composer.json'), $lane, isset($argv[3]) ? readJson($argv[3]) : null);
            file_put_contents('composer.json', json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } else {
            check($command === 'verify', 'Usage: compatibility.php prepare|verify LANE [candidate-manifest]');
            require 'vendor/autoload.php';
            $lock = readJson('composer.lock');
            $installed = readJson('vendor/composer/installed.json');
            $evidence = verify(packages(array_merge($lock['packages'], $lock['packages-dev'])), packages($installed['packages']), $lane);
            echo "Pinned candidate lane; NOT published-installability proof\n";
            echo implode("\n", $evidence)."\n";
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }
}
