<script setup lang="ts">
import { computed } from 'vue'
import WaypointThread from './WaypointThread.vue'

interface Props {
  size?: 'sm' | 'md' | 'lg' | 'xl'
}
const props = withDefaults(defineProps<Props>(), { size: 'md' })

const textClass = computed(() => ({
  sm: 'text-2xl',
  md: 'text-4xl',
  lg: 'text-6xl',
  xl: 'text-8xl',
}[props.size]))

const threadWidth = computed(() => ({ sm: 120, md: 200, lg: 320, xl: 440 }[props.size]))
const threadHeight = computed(() => ({ sm: 6, md: 8, lg: 11, xl: 14 }[props.size]))
</script>

<template>
  <div class="inline-flex flex-col items-start leading-none select-none">
    <span
      :class="[textClass, 'font-sans font-extrabold text-ink']"
      style="letter-spacing: -0.045em;"
    >Throughline</span>
    <WaypointThread
      class="mt-1.5"
      :width="threadWidth"
      :height="threadHeight"
      :dot-count="5"
    />
  </div>
</template>
