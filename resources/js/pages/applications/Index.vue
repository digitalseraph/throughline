<script setup lang="ts">
import ApplicationCard from '@/components/applications/ApplicationCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import draggable from 'vuedraggable';

type AppCard = {
    id: number;
    title: string;
    company: string;
    status: string;
    next_action_at: string | null;
    updated_at: string;
};

const props = defineProps<{
    columns: Record<string, AppCard[]>;
    statuses: string[];
    terminalStatuses: string[];
}>();

// Local reactive copy so draggable can mutate without touching the prop directly.
const localColumns = reactive<Record<string, AppCard[]>>(Object.fromEntries(props.statuses.map((s) => [s, [...(props.columns[s] ?? [])]])));

function labelFor(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function onDrop(event: any, destinationStatus: string) {
    if (event.added) {
        const card = event.added.element as AppCard;
        card.status = destinationStatus;
        router.patch(
            route('applications.update', card.id),
            { status: destinationStatus },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    // Revert UI on failure — reload to recanonicalize.
                    router.reload({ only: ['columns'] });
                },
            },
        );
    }
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
                <div v-for="status in statuses" :key="status" class="w-56 flex-shrink-0">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-3">{{ labelFor(status) }}</span>
                        <span class="text-xs text-ink-3">{{ localColumns[status]?.length ?? 0 }}</span>
                    </div>
                    <div class="min-h-[4rem] rounded-lg bg-bone-2 p-2">
                        <draggable
                            v-model="localColumns[status]"
                            group="applications"
                            item-key="id"
                            class="space-y-2"
                            @change="(e: any) => onDrop(e, status)"
                        >
                            <template #item="{ element: card }">
                                <ApplicationCard :application="card" />
                            </template>
                        </draggable>
                        <p v-if="!localColumns[status]?.length" class="px-2 py-3 text-center text-xs text-ink-3">—</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
