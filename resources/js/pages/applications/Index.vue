<script setup lang="ts">
import ApplicationCard from '@/components/applications/ApplicationCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    columns: Record<string, Array<{
        id: number;
        title: string;
        company: string;
        status: string;
        next_action_at: string | null;
        updated_at: string;
    }>>;
    statuses: string[];
    terminalStatuses: string[];
}>();

function labelFor(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
</script>

<template>
    <Head title="Applications" />
    <AppLayout>
        <div class="px-6 py-10">
            <header class="mb-8">
                <h1 class="font-display text-4xl text-ink">Applications</h1>
                <p class="mt-1 text-ink-3">Track every role across each stage.</p>
            </header>

            <div class="flex gap-4 overflow-x-auto pb-4">
                <div
                    v-for="status in statuses"
                    :key="status"
                    class="flex-shrink-0 w-56"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-3">{{ labelFor(status) }}</span>
                        <span class="text-xs text-ink-3">{{ columns[status]?.length ?? 0 }}</span>
                    </div>
                    <div class="space-y-2 min-h-[4rem] rounded-lg bg-bone-2 p-2">
                        <ApplicationCard
                            v-for="app in columns[status]"
                            :key="app.id"
                            :application="app"
                        />
                        <p v-if="!columns[status]?.length" class="px-2 py-3 text-center text-xs text-ink-3">—</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
