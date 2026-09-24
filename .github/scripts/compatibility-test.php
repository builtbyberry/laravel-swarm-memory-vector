<?php

declare(strict_types=1);

namespace VectorCompatibility;

require __DIR__.'/compatibility.php';
require __DIR__.'/../../vendor/autoload.php';

function package(string $name, string $version, string $ref): array
{
    $repository = $name;

    return [
        'name' => $name, 'version' => $version,
        'source' => ['type' => 'git', 'url' => "https://github.com/{$repository}.git", 'reference' => $ref],
        'dist' => ['type' => 'zip', 'url' => "https://api.github.com/repos/{$repository}/zipball/{$ref}", 'reference' => $ref],
    ];
}

function rejects(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (\RuntimeException) {
        return;
    }
    throw new \RuntimeException("Guard accepted negative control: {$label}");
}

$root = readJson($argv[1] ?? __DIR__.'/../../composer.json');
$candidate = ['name' => CORE, 'require' => ['laravel/ai' => AI_RANGE]];
$controls = 0;
foreach (LANES as $lane) {
    $set = packages([
        package(CORE, '0.27.0', CANDIDATE_REF),
        package('laravel/ai', 'v1.0.0', AI_MINIMUM_REF),
        package('laravel/framework', 'v13.16.0', str_repeat('b', 40)),
    ]);
    $set[CORE]['require']['laravel/ai'] = AI_RANGE;
    verify($set, $set, $lane);
    $prepared = prepare($root, $lane, $candidate);
    check($prepared['repositories'][0]['package']['source']['reference'] === CANDIDATE_REF, 'Candidate must be immutable.');
    check($prepared['require']['laravel/ai'] === ($lane === 'adoption-minimum' ? '1.0.0' : AI_RANGE), 'Wrong AI lane pin.');
    check($prepared['require'][CORE] === '0.27.0', 'Candidate must stay pinned.');

    // Mutate the Composer evidence shape, both independently and in agreement.
    foreach (array_keys($set) as $name) {
        foreach (['version', 'source', 'dist'] as $field) {
            $bad = $set;
            unset($bad[$name][$field]);
            rejects(fn () => verify($set, $bad, $lane), "missing installed {$field}");
            rejects(fn () => verify($bad, $set, $lane), "missing locked {$field}");
            $controls += 2;
        }
        foreach (['dev-main', 'v99.0.0'] as $version) {
            $bad = $set;
            $bad[$name]['version'] = $version;
            rejects(fn () => verify($bad, $bad, $lane), "incorrect {$name} version");
            $controls++;
        }
        $bad = $set;
        $bad[$name]['source']['url'] = 'https://github.com/example/fork.git';
        rejects(fn () => verify($bad, $bad, $lane), 'fork source');
        $bad = $set;
        $bad[$name]['dist']['url'] = 'https://example.com/patched.zip';
        rejects(fn () => verify($bad, $bad, $lane), 'replaced archive');
        $bad = $set;
        $bad[$name]['dist']['reference'] = str_repeat('d', 40);
        rejects(fn () => verify($bad, $bad, $lane), 'archive ref mismatch');
        $bad = $set;
        $bad[$name]['source']['reference'] = 'main';
        $bad[$name]['dist']['reference'] = 'main';
        $bad[$name]['dist']['url'] = 'https://api.github.com/repos/'.$name.'/zipball/main';
        rejects(fn () => verify($bad, $bad, $lane), 'moving source ref');
        $bad = $set;
        $bad[$name]['source']['type'] = 'svn';
        rejects(fn () => verify($bad, $bad, $lane), 'wrong source type');
        $bad = $set;
        $bad[$name]['dist'] = ['type' => 'path', 'url' => '/tmp/local-package', 'reference' => str_repeat('a', 40)];
        rejects(fn () => verify($bad, $bad, $lane), 'path distribution');
        foreach (['version', 'source', 'dist'] as $field) {
            $bad = $set;
            $replacement = package($name, 'v5.1.0', str_repeat('9', 40));
            $bad[$name][$field] = $replacement[$field];
            rejects(fn () => verify($set, $bad, $lane), 'installed field mismatch');
            rejects(fn () => verify($bad, $set, $lane), 'locked field mismatch');
            $controls += 2;
        }
        $controls += 6;
    }
    foreach (['0.11.2', '2.0.0'] as $unsupportedAi) {
        $bad = $set;
        $bad['laravel/ai']['version'] = $unsupportedAi;
        rejects(fn () => verify($bad, $bad, $lane), 'unsupported AI');
        $controls++;
    }
    if ($lane === 'adoption-current') {
        $bad = $set;
        $bad['laravel/ai']['version'] = '2.0.0';
        $bad[CORE]['require']['laravel/ai'] = '^1.0 || ^2.0';
        rejects(fn () => verify($bad, $bad, $lane), 'AI outside companion range even with a broader core contract');
        $controls++;
    }
    $bad = $set;
    $bad[CORE]['version'] = '0.26.3';
    rejects(fn () => verify($bad, $bad, $lane), 'unsupported core');
    $controls++;
    $bad = $set;
    $bad[CORE]['require']['laravel/ai'] = '^0.99';
    rejects(fn () => verify($bad, $bad, $lane), 'unsatisfied actual core AI contract');
    rejects(fn () => verify($set, $bad, $lane), 'lock/install contract mismatch');
    $controls += 2;
    $differentInstalled = $set;
    $differentInstalled['laravel/framework'] = package('laravel/framework', 'v13.17.0', str_repeat('e', 40));
    rejects(fn () => verify($set, $differentInstalled, $lane), 'valid installed package differs from lock');
    $controls++;
    foreach ([CORE, 'laravel/ai'] as $name) {
        if ($name === 'laravel/ai' && $lane !== 'adoption-minimum') {
            continue;
        }
        $bad = $set;
        $bad[$name] = array_replace($set[$name], package($name, $set[$name]['version'], str_repeat('d', 40)));
        rejects(fn () => verify($bad, $bad, $lane), 'wrong pinned commit in otherwise consistent evidence');
        $controls++;
    }
}
rejects(fn () => prepare($root, 'adoption-current', ['name' => 'example/fork', 'require' => ['laravel/ai' => AI_RANGE]]), 'wrong manifest identity');
rejects(fn () => prepare($root, 'adoption-current', ['name' => CORE, 'require' => ['laravel/ai' => '^0.10']]), 'wrong manifest contract');
$badRoot = $root;
$badRoot['require'][CORE] = '^0.26';
rejects(fn () => prepare($badRoot, 'adoption-current', $candidate), 'wrong production core range');
rejects(fn () => verify([], [], 'unknown'), 'unknown lane');
$badRoot = $root;
$badRoot['require']['laravel/ai'] = '^0.11.2';
rejects(fn () => prepare($badRoot, 'adoption-current', $candidate), 'wrong production AI range');
foreach (['repositories', 'replace'] as $override) {
    $badRoot = $root;
    $badRoot[$override] = [];
    rejects(fn () => prepare($badRoot, 'adoption-current', $candidate), 'production provenance override');
    $controls++;
}
$badRoot = $root;
$badRoot['require']['example/aliased'] = 'dev-main as 1.0.0';
rejects(fn () => prepare($badRoot, 'adoption-current', $candidate), 'production version alias');
echo count(LANES).' positive lanes and '.($controls + 6)." negative dependency controls passed.\n";
