<?php

namespace App\Services\Jobs;

use App\Contracts\Jobs\JobSourceDriver;

class DriverRegistry
{
    /** @var array<string, class-string<JobSourceDriver>> */
    protected array $map;

    public function __construct()
    {
        $this->map = config('throughline.drivers', []);
    }

    public function driverFor(string $sourceType): JobSourceDriver
    {
        $class = $this->map[$sourceType] ?? null;
        if (! $class || ! class_exists($class)) {
            throw new \InvalidArgumentException("No driver registered for source_type '{$sourceType}'.");
        }

        return app($class);
    }

    public function supportedTypes(): array
    {
        return array_keys($this->map);
    }
}
