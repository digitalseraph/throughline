<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  source: {
    id?: number;
    source_type: string;
    identifier: string | null;
    display_name: string;
    fetch_cadence_minutes: number;
    is_enabled: boolean;
    last_error?: string | null;
  };
  driverTypes: string[];
}>();

const isEditing = computed(() => !!props.source.id);

const form = useForm({
  source_type: props.source.source_type,
  identifier: props.source.identifier,
  display_name: props.source.display_name,
  fetch_cadence_minutes: props.source.fetch_cadence_minutes,
  is_enabled: props.source.is_enabled,
});

function submit() {
  if (isEditing.value) {
    form.put(route('admin.job-sources.update', props.source.id));
  } else {
    form.post(route('admin.job-sources.store'));
  }
}
</script>

<template>
  <Head :title="isEditing ? 'Edit source' : 'New source'" />
  <AppLayout>
    <div class="mx-auto max-w-xl px-6 py-10">
      <Link :href="route('admin.job-sources.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All sources</Link>
      <h1 class="mt-4 font-display text-4xl text-ink">{{ isEditing ? source.display_name : 'New source' }}</h1>

      <p v-if="source.last_error" class="mt-4 rounded border border-oxblood bg-oxblood/10 px-4 py-3 text-sm text-oxblood">
        Last error: {{ source.last_error }}
      </p>

      <form class="mt-6 space-y-5" @submit.prevent="submit">
        <div>
          <label class="block text-sm text-ink-2">Source type</label>
          <select v-model="form.source_type" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
            <option v-for="t in driverTypes" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm text-ink-2">Identifier (board slug / company / null for aggregators)</label>
          <input v-model="form.identifier" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
          <p v-if="form.errors.identifier" class="mt-1 text-sm text-oxblood">{{ form.errors.identifier }}</p>
        </div>
        <div>
          <label class="block text-sm text-ink-2">Display name</label>
          <input v-model="form.display_name" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
        </div>
        <div>
          <label class="block text-sm text-ink-2">Fetch cadence (minutes)</label>
          <input v-model.number="form.fetch_cadence_minutes" type="number" min="5" max="1440" class="mt-1 w-32 rounded border border-hairline bg-bone-2 px-3 py-2" />
        </div>
        <label class="inline-flex items-center gap-2">
          <input v-model="form.is_enabled" type="checkbox" />
          <span class="text-sm text-ink-2">Enabled</span>
        </label>
        <div class="flex justify-end">
          <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone" :disabled="form.processing">
            {{ isEditing ? 'Save' : 'Create source' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
