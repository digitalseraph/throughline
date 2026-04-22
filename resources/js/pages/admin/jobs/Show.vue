<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
  job: {
    id: number;
    source: string;
    source_board: string | null;
    company_name: string | null;
    title: string;
    location_raw: string | null;
    remote_mode: string | null;
    posted_at: string | null;
    closed_at: string | null;
    raw_payload: Record<string, unknown> | null;
  };
  matches: Array<{
    id: number;
    user_email: string;
    stage1_score: number | null;
    stage2_score: number | null;
    created_at: string;
  }>;
  applications: Array<{
    id: number;
    status: string;
    created_at: string;
    user: { id: number; email: string };
  }>;
}>();

const showPayload = ref(false);
</script>

<template>
  <Head :title="`Admin — ${job.title}`" />
  <AdminLayout>
    <Link :href="route('admin.jobs.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All jobs</Link>

    <h1 class="mt-2 font-display text-3xl text-ink">{{ job.title }}</h1>
    <p class="text-ink-2">{{ job.company_name ?? '—' }} · {{ job.source }}<span v-if="job.source_board"> / {{ job.source_board }}</span></p>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Location</dt>
        <dd class="text-sm text-ink">{{ job.location_raw ?? '—' }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Remote</dt>
        <dd class="text-sm text-ink">{{ job.remote_mode ?? '—' }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Status</dt>
        <dd class="text-sm"><span v-if="job.closed_at" class="text-oxblood">Closed</span><span v-else class="text-ink">Open</span></dd>
      </div>
    </dl>

    <section class="mt-8">
      <h2 class="mb-2 font-display text-xl text-ink">Matches ({{ matches.length }})</h2>
      <table v-if="matches.length" class="w-full border-collapse text-sm">
        <thead class="text-left text-ink-3">
          <tr>
            <th class="py-2 pr-3">User</th>
            <th class="py-2 pr-3">Stage 1 score</th>
            <th class="py-2 pr-3">Stage 2 score</th>
            <th class="py-2 pr-3">Matched</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <tr v-for="match in matches" :key="match.id">
            <td class="py-2 pr-3 text-ink-2">{{ match.user_email }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ match.stage1_score != null ? match.stage1_score.toFixed(3) : '—' }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ match.stage2_score != null ? match.stage2_score.toFixed(3) : '—' }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ new Date(match.created_at).toLocaleDateString() }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="text-sm text-ink-3">No matches yet.</p>
    </section>

    <section class="mt-8">
      <h2 class="mb-2 font-display text-xl text-ink">Applications ({{ applications.length }})</h2>
      <table v-if="applications.length" class="w-full border-collapse text-sm">
        <thead class="text-left text-ink-3">
          <tr>
            <th class="py-2 pr-3">User</th>
            <th class="py-2 pr-3">Status</th>
            <th class="py-2 pr-3">Applied</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <tr v-for="app in applications" :key="app.id">
            <td class="py-2 pr-3 text-ink-2">{{ app.user.email }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ app.status }}</td>
            <td class="py-2 pr-3 text-ink-3">{{ new Date(app.created_at).toLocaleDateString() }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="text-sm text-ink-3">No applications yet.</p>
    </section>

    <section v-if="job.raw_payload" class="mt-8">
      <button type="button" @click="showPayload = !showPayload" class="rounded border border-hairline px-3 py-1 text-sm text-ink-2 hover:text-ink">
        {{ showPayload ? 'Hide' : 'Show' }} raw payload
      </button>
      <pre v-if="showPayload" class="mt-2 overflow-x-auto rounded border border-hairline bg-bone-2/40 p-4 text-xs text-ink-2">{{ JSON.stringify(job.raw_payload, null, 2) }}</pre>
    </section>
  </AdminLayout>
</template>
