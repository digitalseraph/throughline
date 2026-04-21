<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import WaypointThread from '@/components/brand/WaypointThread.vue'

defineProps<{
  user: { name: string }
}>()
</script>

<template>
  <AppLayout>
    <Head title="Dashboard" />
    <div class="max-w-3xl mx-auto py-16 px-8">
      <p class="font-mono text-[11px] uppercase tracking-widest text-oxblood mb-4">Your Throughline</p>
      <h1 class="font-display text-4xl leading-tight text-ink mb-6" style="font-variation-settings: 'opsz' 144, 'SOFT' 30;">
        Welcome, <em class="italic">{{ user.name }}</em>.
      </h1>
      <p class="font-serif italic text-xl text-ink-2 leading-relaxed mb-8">
        Your feed will appear here once you've added a resume and a search profile. We'll begin with your resume.
      </p>
      <WaypointThread :width="240" :height="8" :dot-count="5" />
    </div>
  </AppLayout>
</template>
