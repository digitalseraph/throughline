<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import ProfileForm from '@/components/search-profiles/ProfileForm.vue';

defineProps<{
  profile: Record<string, any>;
  resumes: Array<{ id: number; name: string }>;
}>();
</script>

<template>
  <Head :title="profile.id ? 'Edit search profile' : 'New search profile'" />
  <AppLayout>
    <div class="mx-auto max-w-3xl px-6 py-10">
      <Link :href="route('search-profiles.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All profiles</Link>
      <h1 class="mt-4 font-display text-4xl text-ink">
        {{ profile.id ? profile.name : 'New search profile' }}
      </h1>
      <div class="mt-8">
        <ProfileForm :profile="profile" :resumes="resumes" />
      </div>
    </div>
  </AppLayout>
</template>
