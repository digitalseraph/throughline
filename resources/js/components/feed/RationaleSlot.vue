<script setup lang="ts">
defineProps<{
  match: Record<string, any>;
  showDebug?: boolean;
}>();
</script>

<template>
  <div class="mt-4 rounded border border-dashed border-hairline bg-bone-2/60 px-4 py-3 text-sm text-ink-3">
    <p class="font-medium text-ink-2">AI rationale pending</p>
    <p class="mt-1">Career-arc rationale, gaps, and narrative framing ship in Plan 3 — this is where they will render.</p>
    <p v-if="showDebug" class="mt-2 font-mono text-xs">stage1_score = {{ match.stage1_score.toFixed(4) }}</p>
  </div>
</template>
