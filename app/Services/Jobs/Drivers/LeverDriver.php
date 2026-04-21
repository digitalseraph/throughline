<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class LeverDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'lever';
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

        $url = "https://api.lever.co/v0/postings/{$source->identifier}?mode=json";

        try {
            $response = Http::acceptJson()->timeout(30)->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json() ?? [] as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $categories = $raw['categories'] ?? [];
        $locationRaw = $categories['location'] ?? null;
        $locations = $locationRaw
            ? array_values(array_filter(array_map('trim', explode(' / ', $locationRaw))))
            : [];

        $workplace = strtolower($raw['workplaceType'] ?? 'on-site');
        $remote = match ($workplace) {
            'remote' => 'remote_only',
            'hybrid' => 'hybrid_ok',
            default => 'onsite',
        };

        $salary = $raw['salaryRange'] ?? [];
        $description = trim(($raw['descriptionPlain'] ?? '')."\n\n".($raw['additionalPlain'] ?? ''));

        return new CanonicalJobData(
            sourceId: (string) ($raw['id'] ?? ''),
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['text'] ?? 'Untitled',
            description: $description !== '' ? $description : strip_tags($raw['description'] ?? ''),
            locationRaw: $locationRaw,
            locations: $locations,
            remoteMode: $remote,
            compMin: isset($salary['min']) ? (int) $salary['min'] : null,
            compMax: isset($salary['max']) ? (int) $salary['max'] : null,
            compCurrency: $salary['currency'] ?? null,
            seniority: $categories['level'] ?? null,
            employmentType: $categories['commitment'] ?? null,
            applyUrl: $raw['hostedUrl'] ?? ($raw['applyUrl'] ?? ''),
            postedAt: new DateTimeImmutable('@'.(int) (($raw['createdAt'] ?? time() * 1000) / 1000)),
            rawPayload: $raw,
        );
    }
}
