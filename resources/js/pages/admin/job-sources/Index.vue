<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps<{
  sources: Array<{
    id: number;
    source_type: string;
    identifier: string | null;
    display_name: string;
    is_enabled: boolean;
    fetch_cadence_minutes: number;
    last_fetched_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
  }>;
  driverTypes: string[];
}>();

function fetchNow(id: number) {
  router.post(route('admin.job-sources.fetch-now', id));
}

function destroy(id: number) {
  if (window.confirm('Delete this source? Existing canonical_jobs are kept.')) {
    router.delete(route('admin.job-sources.destroy', id));
  }
}
</script>

<template>
  <Head title="Admin — job sources" />
  <AppLayout>
    <div class="mx-auto max-w-5xl px-6 py-10">
      <header class="mb-8 flex items-end justify-between">
        <h1 class="font-display text-4xl text-ink">Job sources</h1>
        <Link :href="route('admin.job-sources.create')" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone">New source</Link>
      </header>

      <table class="w-full border-collapse text-sm">
        <thead class="text-left text-ink-3">
          <tr>
            <th class="py-2 pr-3">Name</th>
            <th class="py-2 pr-3">Type</th>
            <th class="py-2 pr-3">Identifier</th>
            <th class="py-2 pr-3">Cadence</th>
            <th class="py-2 pr-3">Last fetched</th>
            <th class="py-2 pr-3">Status</th>
            <th class="py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
          <tr v-for="s in sources" :key="s.id">
            <td class="py-3 pr-3">
              <Link :href="route('admin.job-sources.edit', s.id)" class="text-ink hover:text-oxblood">{{ s.display_name }}</Link>
            </td>
            <td class="py-3 pr-3 text-ink-3">{{ s.source_type }}</td>
            <td class="py-3 pr-3 text-ink-3 font-mono text-xs">{{ s.identifier ?? '—' }}</td>
            <td class="py-3 pr-3 text-ink-3">{{ s.fetch_cadence_minutes }}m</td>
            <td class="py-3 pr-3 text-ink-3">{{ s.last_fetched_at ? new Date(s.last_fetched_at).toLocaleString() : 'never' }}</td>
            <td class="py-3 pr-3">
              <span v-if="!s.is_enabled" class="text-oxblood">Paused</span>
              <span v-else-if="s.last_error" class="text-oxblood" :title="s.last_error">Error ({{ s.consecutive_failures }})</span>
              <span v-else class="text-ink-3">OK</span>
            </td>
            <td class="py-3 text-right space-x-3">
              <button class="text-xs text-ink-3 hover:text-oxblood" @click="fetchNow(s.id)">Fetch now</button>
              <button class="text-xs text-ink-3 hover:text-oxblood" @click="destroy(s.id)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
