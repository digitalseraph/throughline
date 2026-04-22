<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
  jobs: {
    data: Array<{
      id: number;
      source: string;
      source_board: string | null;
      company_name: string | null;
      title: string;
      location_raw: string | null;
      remote_mode: string | null;
      posted_at: string | null;
      closed_at: string | null;
    }>;
    links: any[];
  };
  filters: { source: string; company: string; has_embedding: boolean; closed_only: boolean };
  sourceTypes: string[];
}>();

const source = ref(props.filters.source);
const company = ref(props.filters.company);
const hasEmbedding = ref(props.filters.has_embedding);
const closedOnly = ref(props.filters.closed_only);

function applyFilters() {
  router.get(
    route('admin.jobs.index'),
    {
      source: source.value,
      company: company.value,
      has_embedding: hasEmbedding.value ? '1' : '',
      closed_only: closedOnly.value ? '1' : '',
    },
    { preserveState: true, replace: true },
  );
}
</script>

<template>
  <Head title="Admin — Jobs" />
  <AdminLayout>
    <header class="mb-4 flex items-end justify-between">
      <h1 class="font-display text-3xl text-ink">Jobs</h1>
    </header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <select v-model="source" @change="applyFilters" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm">
        <option value="">All sources</option>
        <option v-for="s in sourceTypes" :key="s" :value="s">{{ s }}</option>
      </select>
      <input
        v-model="company"
        @keyup.enter="applyFilters"
        placeholder="Company"
        class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm"
      />
      <label class="flex items-center gap-1 text-sm text-ink-2">
        <input type="checkbox" v-model="hasEmbedding" @change="applyFilters" />
        Has embedding
      </label>
      <label class="flex items-center gap-1 text-sm text-ink-2">
        <input type="checkbox" v-model="closedOnly" @change="applyFilters" />
        Closed only
      </label>
    </div>

    <table class="w-full border-collapse text-sm">
      <thead class="text-left text-ink-3">
        <tr>
          <th class="py-2 pr-3">Company</th>
          <th class="py-2 pr-3">Title</th>
          <th class="py-2 pr-3">Source</th>
          <th class="py-2 pr-3">Location</th>
          <th class="py-2 pr-3">Remote</th>
          <th class="py-2 pr-3">Posted</th>
          <th class="py-2 pr-3">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-hairline">
        <tr v-for="job in jobs.data" :key="job.id">
          <td class="py-2 pr-3 text-ink-2">{{ job.company_name ?? '—' }}</td>
          <td class="py-2 pr-3">
            <Link :href="route('admin.jobs.show', job.id)" class="text-ink hover:text-oxblood">{{ job.title }}</Link>
          </td>
          <td class="py-2 pr-3 text-ink-3">{{ job.source }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ job.location_raw ?? '—' }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ job.remote_mode ?? '—' }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ job.posted_at ? new Date(job.posted_at).toLocaleDateString() : '—' }}</td>
          <td class="py-2 pr-3">
            <span v-if="job.closed_at" class="text-oxblood">Closed</span>
            <span v-else class="text-ink-3">Open</span>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="mt-4 flex flex-wrap gap-1">
      <template v-for="link in jobs.links" :key="link.label">
        <Link
          v-if="link.url"
          :href="link.url"
          class="rounded border border-hairline px-2 py-1 text-xs"
          :class="link.active ? 'bg-ink text-bone' : 'text-ink-2 hover:text-ink'"
        ><span v-html="link.label" /></Link>
        <span v-else class="rounded border border-hairline px-2 py-1 text-xs text-ink-3"><span v-html="link.label" /></span>
      </template>
    </div>
  </AdminLayout>
</template>
