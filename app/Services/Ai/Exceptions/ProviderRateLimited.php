<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class ProviderRateLimited extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(sprintf(
            'Provider %s rate-limited this call; retry after %ds.',
            $provider,
            $retryAfterSeconds,
        ));
    }
}
