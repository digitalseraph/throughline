<?php

namespace App\Services\Matching;

use App\Models\CanonicalJob;
use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Builder;

class HardFilterBuilder
{
    public function __construct(protected SearchProfile $profile) {}

    public function toQuery(): Builder
    {
        $q = CanonicalJob::query()->whereNull('closed_at');

        $mode = $this->profile->remote_mode;
        if ($mode === 'remote_only') {
            $q->where('remote_mode', 'remote_only');
        } elseif ($mode === 'onsite') {
            $q->where('remote_mode', 'onsite');
        } elseif ($mode === 'hybrid_ok') {
            $q->whereIn('remote_mode', ['remote_only', 'hybrid_ok']);
        }

        if ($this->profile->comp_min) {
            $q->where(function ($w) {
                $w->whereNull('comp_min')->orWhere('comp_min', '>=', $this->profile->comp_min);
            });
        }

        foreach ($this->profile->excluded_companies ?? [] as $excluded) {
            $q->where('company_name', 'not ilike', '%'.$excluded.'%');
        }

        return $q;
    }
}
