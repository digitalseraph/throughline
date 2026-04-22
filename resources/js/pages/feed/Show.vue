<script setup lang="ts">
import { echo } from '@/echo';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import JobMatchCard from '@/components/feed/JobMatchCard.vue';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps<{
  profile: { id: number; name: string; resume: { id: number; name: string } | null };
  matches: { data: any[]; links: any[]; meta?: any };
  showDebug: boolean;
}>();

const page = usePage();
const userId = (page.props as any).auth?.user?.id;

onMounted(() => {
  if (userId) {
    echo.private(`users.${userId}`)
      .listen('.FeedMatchReranked', (event: { search_profile_id: number }) => {
        if (event.search_profile_id === props.profile.id) {
          router.reload({ only: ['matches'], preserveScroll: true });
        }
      });
  }
});

onUnmounted(() => {
  if (userId) echo.leave(`users.${userId}`);
});
</script>

<template>
  <Head :title="`Feed — ${profile.name}`" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <Link :href="route('search-profiles.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All profiles</Link>
      <header class="mt-4">
        <h1 class="font-display text-4xl text-ink">{{ profile.name }}</h1>
        <p v-if="profile.resume" class="mt-1 text-sm text-ink-3">Matched against {{ profile.resume.name }}.</p>
        <p v-else class="mt-1 text-sm text-ink-3">No linked resume — matches can't generate until you link one.</p>
      </header>

      <section v-if="matches.data.length" class="mt-8 space-y-4">
        <JobMatchCard v-for="m in matches.data" :key="m.match_id" :match="m" :show-debug="showDebug" />
      </section>

      <div v-else class="mt-10 rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
        <p class="text-ink-2">No matches yet.</p>
        <p class="mt-1 text-sm text-ink-3">Matches appear once your resume is embedded and a drive job fetch has run.</p>
      </div>
    </div>
  </AppLayout>
</template>
