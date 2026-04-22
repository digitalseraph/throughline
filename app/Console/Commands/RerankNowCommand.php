<?php

namespace App\Console\Commands;

use App\Jobs\RerankShortlist;
use App\Models\SearchProfile;
use App\Services\SiteSettings;
use Illuminate\Console\Command;

class RerankNowCommand extends Command
{
    protected $signature = 'throughline:rerank-now {profile : search_profile id} {--size= : shortlist size (defaults to site_settings value)}';

    protected $description = 'Synchronously rerank the top-K stale job_matches for a profile. Useful for dogfood debugging.';

    public function handle(SiteSettings $settings): int
    {
        $profile = SearchProfile::find($this->argument('profile'));
        if (! $profile) {
            $this->error('Profile not found.');

            return self::FAILURE;
        }

        $size = (int) ($this->option('size') ?? $settings->get('rerank_shortlist_size', '20'));

        $this->info("Reranking top {$size} stale matches for profile {$profile->id} ({$profile->name})…");
        RerankShortlist::dispatchSync($profile->id, $size, null);
        $this->info('Done.');

        return self::SUCCESS;
    }
}
