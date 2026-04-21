<script setup lang="ts">
import RationaleSlot from './RationaleSlot.vue';

defineProps<{
  match: {
    match_id: number;
    stage1_score: number;
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
  return {
    remote_only: 'Remote',
    hybrid_ok: 'Hybrid OK',
    onsite: 'On-site',
    any: '—',
  }[mode] ?? mode;
}
</script>

<template>
  <article class="rounded-lg border border-hairline bg-bone px-6 py-5 shadow-sm">
    <header class="flex flex-wrap items-baseline justify-between gap-2">
      <div>
        <h3 class="font-display text-xl text-ink">{{ match.job.title }}</h3>
        <p class="text-sm text-ink-2">{{ match.job.company_name }}</p>
      </div>
      <a :href="match.job.apply_url" target="_blank" rel="noopener" class="text-sm text-oxblood hover:underline">
        View posting &rarr;
      </a>
    </header>

    <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
      <div v-if="match.job.location_raw"><dt class="sr-only">Location</dt><dd>{{ match.job.location_raw }}</dd></div>
      <div><dt class="sr-only">Mode</dt><dd>{{ remoteBadge(match.job.remote_mode) }}</dd></div>
      <div v-if="formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency)">
        <dt class="sr-only">Compensation</dt><dd>{{ formatComp(match.job.comp_min, match.job.comp_max, match.job.comp_currency) }}</dd>
      </div>
      <div v-if="match.job.posted_at">
        <dt class="sr-only">Posted</dt><dd>Posted {{ new Date(match.job.posted_at).toLocaleDateString() }}</dd>
      </div>
    </dl>

    <RationaleSlot :match="match" :show-debug="showDebug" />
  </article>
</template>
