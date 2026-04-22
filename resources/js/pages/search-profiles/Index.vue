<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
  profiles: Array<{ id: number; name: string; is_active: boolean; resume?: { id: number; name: string } | null }>;
}>();
</script>

<template>
  <Head title="Search profiles" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <header class="mb-8 flex items-end justify-between">
        <div>
          <h1 class="font-display text-4xl text-ink">Search profiles</h1>
          <p class="mt-1 text-ink-3">Each profile is a separate ranked feed.</p>
        </div>
        <Link :href="route('search-profiles.create')" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone">
          New profile
        </Link>
      </header>

      <ul v-if="profiles.length" class="divide-y divide-hairline">
        <li v-for="p in profiles" :key="p.id" class="flex items-center justify-between py-4">
          <div>
            <Link :href="route('search-profiles.edit', p.id)" class="font-display text-lg text-ink hover:text-oxblood">{{ p.name }}</Link>
            <p class="text-xs text-ink-3">
              <span v-if="p.resume">Linked to {{ p.resume.name }}</span>
              <span v-else>No linked resume</span>
              · {{ p.is_active ? 'Active' : 'Paused' }}
            </p>
          </div>
          <Link :href="route('feed.show', p.id)" class="text-sm text-oxblood hover:underline">View feed &rarr;</Link>
        </li>
      </ul>

      <div v-else class="rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No profiles yet. Create one to start seeing ranked jobs.</p>
      </div>
    </div>
  </AppLayout>
</template>
