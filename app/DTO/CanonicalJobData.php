<?php

namespace App\DTO;

use DateTimeImmutable;

final readonly class CanonicalJobData
{
    public function __construct(
        public string $sourceId,
        public ?string $sourceBoard,
        public string $companyName,
        public string $title,
        public string $description,
        public ?string $locationRaw,
        public array $locations,
        public string $remoteMode,
        public ?int $compMin,
        public ?int $compMax,
        public ?string $compCurrency,
        public ?string $seniority,
        public ?string $employmentType,
        public string $applyUrl,
        public DateTimeImmutable $postedAt,
        public array $rawPayload,
    ) {}

    public function toEloquentAttributes(string $source): array
    {
        return [
            'source' => $source,
            'source_id' => $this->sourceId,
            'source_board' => $this->sourceBoard,
            'company_name' => $this->companyName,
            'title' => $this->title,
            'description' => $this->description,
            'location_raw' => $this->locationRaw,
            'locations' => $this->locations,
            'remote_mode' => $this->remoteMode,
            'comp_min' => $this->compMin,
            'comp_max' => $this->compMax,
            'comp_currency' => $this->compCurrency,
            'seniority' => $this->seniority,
            'employment_type' => $this->employmentType,
            'apply_url' => $this->applyUrl,
            'posted_at' => $this->postedAt,
            'last_seen_at' => now(),
            'raw_payload' => $this->rawPayload,
        ];
    }
}
