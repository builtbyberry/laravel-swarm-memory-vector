<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmMemoryVector\Exceptions;

use RuntimeException;

/**
 * Thrown when an embedding cannot be produced and the configured failure
 * policy is `throw`. Under the default `store_without_vector` policy this is
 * caught and downgraded to a logged warning so memory writes stay durable.
 */
final class EmbeddingFailedException extends RuntimeException {}
