<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
  profile: {
    id?: number;
    name?: string;
    resume_id?: number | null;
    role_keywords?: string[];
    locations?: string[];
    remote_mode?: string;
    comp_min?: number | null;
    comp_currency?: string | null;
    industries?: string[];
    seniority_levels?: string[];
    excluded_companies?: string[];
    is_active?: boolean;
  };
  resumes: Array<{ id: number; name: string }>;
}>();

const form = useForm({
  name: props.profile.name ?? '',
  resume_id: props.profile.resume_id ?? null,
  role_keywords: props.profile.role_keywords ?? [],
  locations: props.profile.locations ?? [],
  remote_mode: props.profile.remote_mode ?? 'any',
  comp_min: props.profile.comp_min ?? null,
  comp_currency: props.profile.comp_currency ?? 'USD',
  industries: props.profile.industries ?? [],
  seniority_levels: props.profile.seniority_levels ?? [],
  excluded_companies: props.profile.excluded_companies ?? [],
  is_active: props.profile.is_active ?? true,
});

const isEditing = computed(() => !!props.profile.id);

function submit() {
  if (isEditing.value) {
    form.put(route('search-profiles.update', props.profile.id));
  } else {
    form.post(route('search-profiles.store'));
  }
}

function destroyProfile() {
  if (window.confirm('Delete this profile?')) {
    form.delete(route('search-profiles.destroy', props.profile.id));
  }
}

function keywordList(field: 'role_keywords' | 'locations' | 'industries' | 'seniority_levels' | 'excluded_companies') {
  return {
    get: () => (form[field] as string[]).join(', '),
    set: (value: string) => {
      form[field] = value.split(',').map((s) => s.trim()).filter(Boolean);
    },
  };
}

const roleKeywordsInput = keywordList('role_keywords');
const locationsInput = keywordList('locations');
const industriesInput = keywordList('industries');
const seniorityInput = keywordList('seniority_levels');
const excludedInput = keywordList('excluded_companies');
</script>

<template>
  <form class="space-y-5" @submit.prevent="submit">
    <div>
      <label class="block text-sm text-ink-2">Name</label>
      <input v-model="form.name" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
      <p v-if="form.errors.name" class="mt-1 text-sm text-oxblood">{{ form.errors.name }}</p>
    </div>

    <div>
      <label class="block text-sm text-ink-2">Linked resume</label>
      <select v-model="form.resume_id" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
        <option :value="null">— none —</option>
        <option v-for="r in resumes" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <div>
        <label class="block text-sm text-ink-2">Role keywords (comma-separated)</label>
        <input :value="roleKeywordsInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => roleKeywordsInput.set((e.target as HTMLInputElement).value)" />
      </div>
      <div>
        <label class="block text-sm text-ink-2">Locations</label>
        <input :value="locationsInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => locationsInput.set((e.target as HTMLInputElement).value)" />
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <div>
        <label class="block text-sm text-ink-2">Remote mode</label>
        <select v-model="form.remote_mode" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2">
          <option value="any">Any</option>
          <option value="remote_only">Remote only</option>
          <option value="hybrid_ok">Hybrid OK</option>
          <option value="onsite">On-site</option>
        </select>
      </div>
      <div>
        <label class="block text-sm text-ink-2">Min comp</label>
        <input v-model.number="form.comp_min" type="number" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm text-ink-2">Currency</label>
        <input v-model="form.comp_currency" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" maxlength="3" />
      </div>
    </div>

    <div>
      <label class="block text-sm text-ink-2">Industries</label>
      <input :value="industriesInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => industriesInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <div>
      <label class="block text-sm text-ink-2">Seniority levels</label>
      <input :value="seniorityInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => seniorityInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <div>
      <label class="block text-sm text-ink-2">Excluded companies</label>
      <input :value="excludedInput.get()" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" @input="(e) => excludedInput.set((e.target as HTMLInputElement).value)" />
    </div>

    <label class="inline-flex items-center gap-2">
      <input v-model="form.is_active" type="checkbox" />
      <span class="text-sm text-ink-2">Active (generate matches for this profile)</span>
    </label>

    <div class="flex justify-between">
      <button v-if="isEditing" type="button" class="text-sm text-ink-3 hover:text-oxblood" @click="destroyProfile">Delete profile</button>
      <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone disabled:opacity-50" :disabled="form.processing">
        {{ isEditing ? 'Save changes' : 'Create profile' }}
      </button>
    </div>
  </form>
</template>
