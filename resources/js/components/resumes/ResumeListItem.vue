<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
  resume: { id: number; name: string; parse_status: string; is_default: boolean; created_at: string };
}>();
</script>

<template>
  <li class="flex items-center justify-between py-4">
    <div>
      <Link :href="route('resumes.show', resume.id)" class="font-display text-lg text-ink hover:text-oxblood">
        {{ resume.name }}
      </Link>
      <p class="text-xs text-ink-3">
        {{ resume.parse_status === 'parsed' ? 'Parsed' : resume.parse_status === 'parse_failed' ? 'Parse failed' : 'Parsing…' }}
      </p>
    </div>
    <span v-if="resume.is_default" class="rounded-full border border-oxblood px-3 py-1 text-xs font-medium text-oxblood">
      Default
    </span>
  </li>
</template>
