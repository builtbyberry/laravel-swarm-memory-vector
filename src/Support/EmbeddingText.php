<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Support;

/**
 * Decides what text, if any, represents a memory value for embedding.
 *
 * v0.1.0 embeds string values only: a semantic vector over a structured
 * array or scalar is rarely meaningful, and non-string values remain fully
 * available through the exact-key `recall` tool. A memory value that yields
 * no embeddable text simply is not indexed for semantic recall.
 */
final class EmbeddingText
{
    public static function from(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
