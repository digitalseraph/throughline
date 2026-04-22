<?php

namespace App\Services\Jobs\Drivers;

use App\Contracts\Jobs\JobSourceDriver;
use App\DTO\CanonicalJobData;
use App\Models\JobSource;
use App\Services\Jobs\Exceptions\DriverFetchFailed;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\Http;

class USAJobsDriver implements JobSourceDriver
{
    public function sourceType(): string
    {
        return 'usajobs';
    }

    public function validateIdentifier(?string $identifier): bool
    {
        return true; // Single-feed API.
    }

    public function fetch(JobSource $source): Generator
    {
        $authKey = config('services.usajobs.auth_key');
        $userAgentEmail = config('services.usajobs.user_agent_email');

        if (!$authKey || !$userAgentEmail) {
            throw new DriverFetchFailed($this->sourceType(), null, 'USAJobs credentials not configured (USAJOBS_AUTH_KEY, USAJOBS_USER_AGENT_EMAIL)');
        }

        $maxPages = (int) config('services.usajobs.max_pages', 10);
        $perPage = (int) config('services.usajobs.results_per_page', 25);

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = Http::acceptJson()->timeout(30)->withHeaders([
                    'Authorization-Key' => $authKey,
                    'User-Agent' => "ThroughlineJobIngestor/1.0 ({$userAgentEmail})",
                    'Host' => 'data.usajobs.gov',
                ])->get('https://data.usajobs.gov/api/search', [
                    'Page' => $page,
                    'ResultsPerPage' => $perPage,
                ]);
            } catch (\Throwable $e) {
                throw new DriverFetchFailed($this->sourceType(), null, 'HTTP error: '.$e->getMessage(), $e);
            }

            if ($response->failed()) {
                throw new DriverFetchFailed($this->sourceType(), null, "Upstream returned {$response->status()} on page {$page}");
            }

            $items = $response->json('SearchResult.SearchResultItems') ?? [];
            if (empty($items)) {
                return;
            }

            foreach ($items as $item) {
                yield $this->normalize($item, $source);
            }
        }
    }

    protected function normalize(array $item, JobSource $source): CanonicalJobData
    {
        $desc = $item['MatchedObjectDescriptor'] ?? [];
        [$compMin, $compMax] = $this->extractComp($desc['PositionRemuneration'] ?? []);
        $locationRaw = $desc['PositionLocationDisplay'] ?? null;

        $remote = $locationRaw && stripos($locationRaw, 'remote') !== false ? 'remote_only' : 'onsite';

        $description = trim(
            ($desc['UserArea']['Details']['JobSummary'] ?? '')."\n\n".
            ($desc['QualificationSummary'] ?? ''),
        );

        return new CanonicalJobData(
            sourceId: (string) ($item['MatchedObjectId'] ?? ''),
            sourceBoard: 'federal',
            companyName: $desc['OrganizationName'] ?? 'U.S. Federal Government',
            title: $desc['PositionTitle'] ?? 'Untitled',
            description: $description,
            locationRaw: $locationRaw,
            locations: $locationRaw ? [$locationRaw] : [],
            remoteMode: $remote,
            compMin: $compMin,
            compMax: $compMax,
            compCurrency: $compMin !== null ? 'USD' : null,
            seniority: null,
            employmentType: null,
            applyUrl: $desc['ApplyURI'][0] ?? ($desc['PositionURI'] ?? ''),
            postedAt: new DateTimeImmutable($desc['PublicationStartDate'] ?? 'now'),
            rawPayload: $item,
        );
    }

    protected function extractComp(array $remuneration): array
    {
        foreach ($remuneration as $entry) {
            if (!empty($entry['MinimumRange'])) {
                return [
                    (int) $entry['MinimumRange'],
                    !empty($entry['MaximumRange']) ? (int) $entry['MaximumRange'] : null,
                ];
            }
        }

        return [null, null];
    }
}
