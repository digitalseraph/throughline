<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

class CostCeilingExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly float $currentSpendUsd,
        public readonly float $ceilingUsd,
    ) {
        parent::__construct(sprintf(
            'User %d has reached the daily AI cost ceiling (spent $%.4f of $%.2f).',
            $userId,
            $currentSpendUsd,
            $ceilingUsd,
        ));
    }
}
