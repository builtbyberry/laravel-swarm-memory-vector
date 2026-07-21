<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Support;

/**
 * Cosine similarity for the portable "scan" index driver. The native pgvector
 * driver computes this in the database via the `<=>` operator instead.
 */
final class Cosine
{
    /**
     * Cosine similarity of two equal-length vectors, in the range [-1, 1].
     * Returns 0.0 when either vector has zero magnitude.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $componentA) {
            $componentB = $b[$i] ?? 0.0;
            $dot += $componentA * $componentB;
            $magA += $componentA * $componentA;
            $magB += $componentB * $componentB;
        }

        if ($magA === 0.0 || $magB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
