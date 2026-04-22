<?php

namespace App\Services\Jobs\Exceptions;

use RuntimeException;
use Throwable;

class DriverFetchFailed extends RuntimeException
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $identifier,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('[%s:%s] %s', $sourceType, $identifier ?? '-', $message),
            0,
            $previous,
        );
    }
}
