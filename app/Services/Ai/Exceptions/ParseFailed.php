<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class ParseFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $rawResponse = null,
    ) {
        parent::__construct("Resume parse failed: {$reason}");
    }
}
