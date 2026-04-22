<script setup lang="ts">
import RationaleSlot from './RationaleSlot.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    match: {
        match_id: number;
        stage1_score: number;
        stage2_score: number | null;
        stage2_rationale: string | null;
        stage2_gaps: Array<{ requirement: string; severity: 'blocker' | 'stretch' | 'minor' }> | null;
        stage2_narrative: string | null;
        stage2_at: string | null;
        is_saved: boolean;
        job: {
            id: number;
            title: string;
            company_name: string;
            location_raw: string | null;
            remote_mode: string;
            comp_min: number | null;
            comp_max: number | null;
            comp_currency: string | null;
            posted_at: string | null;
            apply_url: string;
        };
    };
    showDebug?: boolean;
}>();

function formatComp(min: number | null, max: number | null, currency: string | null) {
    if (!min && !max) return null;
    const cur = currency ?? 'USD';
    if (min && max) return `${cur} ${Math.round(min / 1000)}k–${Math.round(max / 1000)}k`;
    return `${cur} ${Math.round((min ?? max ?? 0) / 1000)}k+`;
}

function remoteBadge(mode: string) {
    return (
        (
            {
                remote_only: 'Remote',
                hybrid_ok: 'Hybrid OK',
                onsite: 'On-site',
                any: '—',
            } as Record<string, string>
        )[mode] ?? mode
    );
}

function fitScoreClass(score: number | null) {
    if (score === null) return 'bg-bone-2 text-ink-3';
    if (score >= 80) return 'bg-oxblood text-bone';
    if (score >= 60) return 'bg-oxblood/15 text-oxblood';
    return 'bg-bone-2 text-ink-3';
}

function save() {
    router.post(route('applications.store'), { job_match_id: props.match.match_id }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['matches'] }),
    });
}
</script>

<template>
    <article class="rounded-lg border border-hairline bg-bone px-6 py-5 shadow-sm">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex-1">
                <div class="flex items-center gap-3">
                    <h3 class="font-display text-xl text-ink">{{ match.job.title }}</h3>
                    <span
                        v-if="match.stage2_score !== null && match.stage2_rationale !== 'rerank unavailable'"
                        :class="['rounded-full px-2.5 py-0.5 text-xs font-medium tracking-tight', fitScoreClass(match.stage2_score)]"
                    >
                        {{ match.stage2_score }}
                    </span>
                    <button
                        v-if="!match.is_saved"
                        type="button"
                        class="rounded-full border border-ink-3 px-3 py-0.5 text-xs hover:border-oxblood hover:text-oxblood"
                        @click="save"
                    >
                        Save
                    </button>
                    <span
                        v-else
                        class="rounded-full border border-oxblood bg-oxblood/10 px-3 py-0.5 text-xs text-oxblood"
                    >
                        Saved
                    </span>
                </div>
                <p class="mt-1 text-sm text-ink-2">
                    <span v-if="match.stage2_narrative && match.stage2_rationale !== 'rerank unavailable'">{{ match.stage2_narrative }}</span>
                    <span v-else>{{ match.job.company_name }}</span>
                </p>
            </div>
            <a :href="match.job.apply_url" target="_blank" rel="noopener" class="text-sm text-oxblood hover:underline"> View posting &rarr; </a>
        </header>

        <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
            <div>
                <dt class="sr-only">Company</dt>
                <dd>{{ match.job.company_name }}</dd>
            </div>
            <div v-if="match.job.location_raw">
                <dt class="sr-only">Location</dt>
                <dd>{{ match.job.location_raw }}</dd>
            </div>
            <div>
                <dt class="sr-only">Mode</dt>
                <dd>{{ remoteBadge(match.job.remote_mode) }}</dd>
            </div>
            <div v-if="formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency)">
                <dt class="sr-only">Compensation</dt>
                <dd>{{ formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency) }}</dd>
            </div>
            <div v-if="match.job.posted_at">
                <dt class="sr-only">Posted</dt>
                <dd>Posted {{ new Date(match.job.posted_at).toLocaleDateString() }}</dd>
            </div>
        </dl>

        <RationaleSlot :match="match" :show-debug="showDebug" />
    </article>
</template>
