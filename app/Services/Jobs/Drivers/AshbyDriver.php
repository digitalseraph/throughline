<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class AshbyDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'ashby';
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

        $url = "https://api.ashbyhq.com/posting-api/job-board/{$source->identifier}";

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
        [$compMin, $compMax, $compCurrency] = $this->extractComp($raw['compensation'] ?? null);

        $remote = ($raw['isRemote'] ?? false) ? 'remote_only' : 'onsite';
        $locationRaw = $raw['locationName'] ?? null;

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: ucfirst($source->identifier ?? 'Unknown'),
            title: $raw['title'] ?? 'Untitled',
            description: trim($raw['descriptionPlain'] ?? strip_tags($raw['descriptionHtml'] ?? '')),
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compCurrency,
            seniority: null,
            employmentType: $raw['employmentType'] ?? null,
            applyUrl: $raw['jobUrl'] ?? '',
            postedAt: new DateTimeImmutable($raw['publishedAt'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function extractComp(?array $compensation): array
    {
        if (! $compensation) {
            return [null, null, null];
        }

        $summary = $compensation['compensationTierSummary']
            ?? $compensation['scrapeableCompensationSalarySummary']
            ?? null;

        if (! $summary || ! preg_match('/\$?([\d,]+)(?:\s*-\s*\$?([\d,]+))?\s*([A-Z]{3})?/', $summary, $m)) {
            return [null, null, null];
        }

        return [
            (int) str_replace(',', '', $m[1]),
            isset($m[2]) && $m[2] !== '' ? (int) str_replace(',', '', $m[2]) : null,
            $m[3] ?? 'USD',
        ];
    }
}
