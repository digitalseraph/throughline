<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class GreenhouseDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'greenhouse';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return is_string($identifier) && strlen(trim($identifier)) > 0;
    }

    public function fetch(JobSource $source): Generator
    {
        if (! $this->validateIdentifier($source->identifier)) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'identifier required');
        }

        $url = "https://boards-api.greenhouse.io/v1/boards/{$source->identifier}/jobs?content=true";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        $body = $response->json();
        $jobs = $body['jobs'] ?? [];

        foreach ($jobs as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        [$compMin, $compMax, $compCurrency] = $this->extractComp($raw['metadata'] ?? []);
        $locations = $this->extractLocations($raw['offices'] ?? [], $raw['location']['name'] ?? null);
        $remote = $this->inferRemote($locations, $raw['location']['name'] ?? '');

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: strip_tags($raw['content'] ?? ''),
            locationRaw: $raw['location']['name'] ?? null,
            locations: $locations,
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: null,
            applyUrl: $raw['absolute_url'] ?? '',
            postedAt: new DateTimeImmutable($raw['updated_at'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function extractComp(array $metadata): array
    {
        foreach ($metadata as $entry) {
            $name = strtolower($entry['name'] ?? '');
            if (! str_contains($name, 'compensation') && ! str_contains($name, 'salary')) {
                continue;
            }
            $value = (string) ($entry['value'] ?? '');
            if (preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?\s*([A-Z]{3})?/', $value, $m)) {
                return [
                    (int) str_replace(',', '', $m[1]),
                    isset($m[2]) && $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null,
                    $m[3] ?? 'USD',
                ];
            }
        }

        return [null, null, null];
    }

    protected function extractLocations(array $offices, ?string $fallback): array
    {
        $names = array_filter(array_map(fn ($o) => $o['name'] ?? null, $offices));

        return $names ?: array_filter([$fallback]);
    }

    protected function inferRemote(array $locations, string $raw): string
    {
        $haystack = strtolower(implode(' ', $locations).' '.$raw);
        if (str_contains($haystack, 'remote')) {
            return count($locations) > 1 ? 'hybrid_ok' : 'remote_only';
        }

        return 'onsite';
    }
}
