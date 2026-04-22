<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class RerankFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $rawResponse = null,
    ) {
        parent::__construct("Rerank failed: {$reason}");
    }
}
