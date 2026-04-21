<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  width?: number
  height?: number
  dotCount?: number
  color?: string
}
const props = withDefaults(defineProps<Props>(), {
  width: 240,
  height: 10,
  dotCount: 5,
  color: '#a83a1a',
})

const positions = computed<number[]>(() => {
  const pad = props.height / 2
  const span = props.width - pad * 2
  const count = Math.max(2, props.dotCount)
  const out: number[] = []
  for (let i = 0; i < count; i++) {
    out.push(pad + (span * i) / (count - 1))
  }
  return out
})

const radius = computed(() => props.height / 2.2)
const strokeWidth = computed(() => props.height / 3)
</script>

<template>
  <svg
    :width="width"
    :height="height"
    :viewBox="`0 0 ${width} ${height}`"
    class="overflow-visible"
    role="img"
    aria-label="Throughline waypoint thread"
  >
    <line
      :x1="height / 2"
      :y1="height / 2"
      :x2="width - height / 2"
      :y2="height / 2"
      :stroke="color"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
    />
    <circle
      v-for="(cx, i) in positions"
      :key="i"
      :cx="cx"
      :cy="height / 2"
      :r="radius"
      :fill="color"
    />
  </svg>
</template>
