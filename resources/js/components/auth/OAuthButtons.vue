<script setup lang="ts">
const providers = [
  { key: 'google', label: 'Continue with Google' },
  { key: 'github', label: 'Continue with GitHub' },
] as const
</script>

<template>
  <div class="flex flex-col gap-2 mt-4">
    <a
      v-for="p in providers"
      :key="p.key"
      :href="`/auth/${p.key}/redirect`"
      class="inline-flex items-center justify-center gap-2 h-10 px-4 rounded-md border border-hairline bg-bone hover:bg-bone-2 text-ink font-medium transition-colors"
    >
      <span class="inline-block w-2 h-2 rounded-full bg-oxblood"></span>
      {{ p.label }}
    </a>
  </div>
  <div class="relative my-4">
    <div class="absolute inset-0 flex items-center">
      <div class="w-full border-t border-hairline"></div>
    </div>
    <div class="relative flex justify-center">
      <span class="bg-bone px-3 text-xs font-mono tracking-widest text-ink-3 uppercase">or</span>
    </div>
  </div>
</template>
