<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class RemoteOkDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'remoteok';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return true;
    }

    public function fetch(JobSource $source): Generator
    {
        $url = 'https://remoteok.com/api';

        try {
            $response = Http::withHeaders(['User-Agent' => 'ThroughlineJobIngestor/1.0'])
                ->acceptJson()
                ->timeout(30)
                ->get($url);
        } catch (\Throwable $e) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()}");
        }

        foreach ($response->json() ?? [] as $raw) {
            if (! isset($raw['id']) || ! isset($raw['position'])) {
                continue;
            }

            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $salaryMin = array_key_exists('salary_min', $raw) ? $raw['salary_min'] : null;
        $salaryMax = array_key_exists('salary_max', $raw) ? $raw['salary_max'] : null;

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: 'global',
            companyName: $raw['company'] ?? 'Unknown',
            title: $raw['position'],
            description: strip_tags($raw['description'] ?? ''),
            locationRaw: $raw['location'] ?? null,
            locations: array_filter([$raw['location'] ?? null]),
            remoteMode: 'remote_only',
            compMin: $salaryMin !== null ? (int) $salaryMin : null,
            compMax: $salaryMax !== null ? (int) $salaryMax : null,
            compCurrency: null,
            seniority: null,
            employmentType: null,
            applyUrl: $raw['apply_url'] ?? ($raw['url'] ?? ''),
            postedAt: new DateTimeImmutable($raw['date'] ?? 'now'),
            rawPayload: $raw,
        );
    }
}
