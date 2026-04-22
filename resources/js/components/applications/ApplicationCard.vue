<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    application: {
        id: number;
        title: string;
        company: string;
        status: string;
        next_action_at: string | null;
        updated_at: string;
    };
}>();

function truncate(str: string, len = 40): string {
    return str.length > len ? str.slice(0, len) + '…' : str;
}
</script>

<template>
    <Link
        :href="route('applications.show', application.id)"
        class="block rounded-lg border border-hairline bg-white px-4 py-3 transition-colors hover:border-oxblood/40 hover:bg-bone-2"
    >
        <p class="truncate text-sm font-medium text-ink" :title="application.title">{{ truncate(application.title) }}</p>
        <p class="mt-0.5 truncate text-xs text-ink-3" :title="application.company">{{ truncate(application.company, 32) }}</p>
        <p v-if="application.next_action_at" class="mt-1.5 text-xs text-ink-2">Next: {{ application.next_action_at }}</p>
    </Link>
</template>
