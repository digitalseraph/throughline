<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class AdzunaDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'adzuna';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        // Adzuna's identifier is the country code; accepted values are short strings.
        return is_string($identifier) && preg_match('/^[a-z]{2,3}$/', $identifier) === 1;
    }

    public function fetch(JobSource $source): Generator
    {
        $appId = config('services.adzuna.app_id');
        $appKey = config('services.adzuna.app_key');

        if (! $appId || ! $appKey) {
            throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'Adzuna credentials not configured (ADZUNA_APP_ID / ADZUNA_APP_KEY)');
        }

        $country = $source->config_json['country'] ?? $source->identifier ?? 'us';
        $maxPages = (int) config('services.adzuna.max_pages', 20);
        $perPage = (int) config('services.adzuna.results_per_page', 50);

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = sprintf('https://api.adzuna.com/v1/api/jobs/%s/search/%d', $country, $page);

            try {
                $response = Http::acceptJson()->timeout(30)->get($url, [
                    'app_id' => $appId,
                    'app_key' => $appKey,
                    'results_per_page' => $perPage,
                    'content-type' => 'application/json',
                ]);
            } catch (\Throwable $e) {
                throw new DriverFetchFailed($this->sourceType(), $source->identifier, 'HTTP error: '.$e->getMessage(), $e);
            }

            if ($response->failed()) {
                throw new DriverFetchFailed($this->sourceType(), $source->identifier, "Upstream returned {$response->status()} on page {$page}");
            }

            $results = $response->json('results') ?? [];
            if (empty($results)) {
                return;
            }

            foreach ($results as $raw) {
                yield $this->normalize($raw, $source);
            }
        }
    }

    protected function normalize(array $raw, JobSource $source): CanonicalJobData
    {
        $locationRaw = $raw['location']['display_name'] ?? null;
        $isRemote = $locationRaw && stripos($locationRaw, 'remote') !== false;
        $remote = $isRemote ? 'remote_only' : 'onsite';

        $country = $source->config_json['country'] ?? $source->identifier ?? 'us';
        $currency = $this->currencyForCountry($country);

        return new CanonicalJobData(
            sourceId: (string) $raw['id'],
            sourceBoard: $source->identifier,
            companyName: $raw['company']['display_name'] ?? 'Unknown',
            title: $raw['title'] ?? 'Untitled',
            description: trim(strip_tags($raw['description'] ?? '')),
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: isset($raw['salary_min']) && $raw['salary_min'] ? (int) $raw['salary_min'] : null,
            compMax: isset($raw['salary_max']) && $raw['salary_max'] ? (int) $raw['salary_max'] : null,
            compCurrency: isset($raw['salary_min']) && $raw['salary_min'] ? $currency : null,
            seniority: null,
            employmentType: $raw['contract_type'] ?? null,
            applyUrl: $raw['redirect_url'] ?? '',
            postedAt: new DateTimeImmutable($raw['created'] ?? 'now'),
            rawPayload: $raw,
        );
    }

    protected function currencyForCountry(string $country): string
    {
        return match (strtolower($country)) {
            'us' => 'USD',
            'gb' => 'GBP',
            'au' => 'AUD',
            'ca' => 'CAD',
            'de', 'fr', 'nl', 'it', 'es', 'at', 'be', 'ie' => 'EUR',
            'pl' => 'PLN',
            'in' => 'INR',
            'nz' => 'NZD',
            'za' => 'ZAR',
            'sg' => 'SGD',
            'ch' => 'CHF',
            'mx' => 'MXN',
            'br' => 'BRL',
            'ru' => 'RUB',
            default => 'USD',
        };
    }
}
