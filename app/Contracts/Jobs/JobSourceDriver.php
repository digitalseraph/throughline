<?php

namespace App\Contracts\Jobs;

use App\DTO\CanonicalJobData;
use App\Models\JobSource;

interface JobSourceDriver
{
    public function sourceType(): string;

    /**
     * @return iterable<CanonicalJobData>
     */
    public function fetch(JobSource $source): iterable;

    public function validateIdentifier(?string $identifier): bool;
}
