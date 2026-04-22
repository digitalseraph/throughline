<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class RemotiveDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'remotive';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return true;
    }

    public function fetch(JobSource $source): Generator
    {
        $url = 'https://remotive.com/api/remote-jobs';

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

        $data = $response->json();
        $jobs = $data['jobs'] ?? [];

        foreach ($jobs as $raw) {
            yield $this->normalize($raw, $source);
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        [$compMin, $compMax, $compCurrency] = $this->parseSalary($raw['salary'] ?? '');

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: 'global',
            companyName: $raw['company_name'] ?? 'Unknown',
            title: $raw['title'],
            description: strip_tags($raw['description'] ?? ''),
            locationRaw: $raw['candidate_required_location'] ?? null,
            locations: array_filter([$raw['candidate_required_location'] ?? null]),
            remoteMode: 'remote_only',
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: $raw['job_type'] ?? null,
            applyUrl: $raw['url'] ?? '',
            postedAt: new DateTimeImmutable($raw['publication_date'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function parseSalary(string $salary): array
    {
        if ($salary === '') {
            return [null, null, null];
        }

        if (preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?/', $salary, $matches)) {
            $min = (int) str_replace(',', '', $matches[1]);
            $max = isset($matches[2]) && $matches[2] !== '' ? (int) str_replace(',', '', $matches[2]) : null;

            return [$min, $max, 'USD'];
        }

        return [null, null, null];
    }
}
