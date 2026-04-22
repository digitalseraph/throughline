<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class WorkableDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'workable';
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

        $url = "https://apply.workable.com/api/v1/widget/accounts/{$source->identifier}?details=true";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json('jobs') ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $location = $raw['location'] ?? [];
        $remote = ($location['telecommuting'] ?? false) ? 'remote_only' : 'onsite';

        $locationParts = array_filter([
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['country'] ?? null,
        ]);
        $locationRaw = $locationParts ? implode(', ', $locationParts) : null;

        $description = trim(strip_tags(
            ($raw['description'] ?? '')."\n\n".
            ($raw['requirements'] ?? '')."\n\n".
            ($raw['benefits'] ?? ''),
        ));

        return new CanonicalJobData(
            sourceId: (string) ($raw['id'] ?? $raw['shortcode']),
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: $description,
            locationRaw: $locationRaw,
            locations: array_values($locationParts),
            remoteMode: $remote,
            compMin: null,
            compMax: null,
            compCurrency: null,
            seniority: $raw['experience'] ?? null,
            employmentType: $raw['employment_type'] ?? null,
            applyUrl: $raw['application_url'] ?? ($raw['url'] ?? ''),
            postedAt: new DateTimeImmutable($raw['created_at'] ?? 'now'),
            rawPayload: $raw,
        );
    }
}
