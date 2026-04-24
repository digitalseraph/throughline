<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Wordmark from '@/components/brand/Wordmark.vue'
import WaypointThread from '@/components/brand/WaypointThread.vue'

defineProps<{
  canLogin: boolean
  canRegister: boolean
  siteName: string
  tagline: string
}>()
</script>

<template>
  <Head :title="siteName" />

  <div class="min-h-screen bg-bone text-ink flex flex-col">
    <header class="flex items-center justify-between px-8 py-6">
      <Link href="/" class="inline-flex"><Wordmark size="sm" /></Link>
      <nav class="flex items-center gap-6 font-mono text-xs uppercase tracking-widest text-ink-3">
        <Link href="/about" class="hover:text-oxblood transition-colors">About</Link>
        <Link href="/help" class="hover:text-oxblood transition-colors">Help</Link>
        <Link v-if="canLogin" href="/login" class="hover:text-oxblood transition-colors">Sign in</Link>
        <Link v-if="canRegister" href="/register" class="hover:text-oxblood transition-colors">Create account</Link>
      </nav>
    </header>

    <main class="flex-1 flex flex-col items-center justify-center px-8 text-center max-w-3xl mx-auto">
      <Wordmark size="xl" class="mb-10" />
      <p class="font-display italic text-3xl leading-snug text-ink max-w-2xl mb-6" style="font-variation-settings: 'opsz' 144, 'SOFT' 40;">
        {{ tagline }}
      </p>
      <p class="font-sans text-base text-ink-2 max-w-xl mb-10 leading-relaxed">
        Upload your resume. We find roles that fit the arc you're already writing &mdash; and tell you exactly why, line by line.
      </p>
      <div class="flex gap-4">
        <Link
          v-if="canRegister"
          href="/register"
          class="inline-flex items-center justify-center h-11 px-6 rounded-md bg-oxblood text-bone font-medium hover:opacity-90 transition-opacity"
        >Get started</Link>
        <Link
          v-if="canLogin"
          href="/login"
          class="inline-flex items-center justify-center h-11 px-6 rounded-md border border-hairline text-ink hover:bg-bone-2 transition-colors"
        >Sign in</Link>
      </div>
    </main>

    <footer class="flex items-center justify-center gap-6 py-8 font-mono text-[10px] uppercase tracking-widest text-ink-3">
      <span>{{ siteName }}</span>
      <WaypointThread :width="120" :height="6" :dot-count="5" />
      <span>Since 2026</span>
    </footer>
  </div>
</template>
