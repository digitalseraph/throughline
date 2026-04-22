<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    cards: {
        total_users: number;
        active_7d: number;
        total_jobs: number;
        jobs_today: number;
        total_matches: number;
        applications_by_status: Record<string, number>;
        ai_spend_today: number;
        ai_spend_month: number;
        sources: Array<{
            id: number;
            source_type: string;
            identifier: string | null;
            display_name: string;
            is_enabled: boolean;
            last_fetched_at: string | null;
            last_error: string | null;
            consecutive_failures: number;
        }>;
    };
}>();

function statusColor(source: { is_enabled: boolean; last_error: string | null; consecutive_failures: number; last_fetched_at: string | null }) {
    if (!source.is_enabled) return 'text-oxblood';
    if (source.last_error || source.consecutive_failures > 0) return 'text-oxblood';
    if (!source.last_fetched_at) return 'text-ink-3';
    const ageMs = Date.now() - new Date(source.last_fetched_at).getTime();
    return ageMs > 1000 * 60 * 60 * 6 ? 'text-ink-3' : 'text-ink-2';
}
</script>

<template>
    <Head title="Admin — Overview" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Overview</h1>

        <section class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
                <p class="text-xs text-ink-3">Users</p>
                <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_users }}</p>
                <p class="text-xs text-ink-3">{{ cards.active_7d }} active in 7d</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
                <p class="text-xs text-ink-3">Jobs</p>
                <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_jobs }}</p>
                <p class="text-xs text-ink-3">{{ cards.jobs_today }} today</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
                <p class="text-xs text-ink-3">Matches</p>
                <p class="mt-1 font-display text-2xl text-ink">{{ cards.total_matches }}</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
                <p class="text-xs text-ink-3">AI spend</p>
                <p class="mt-1 font-display text-2xl text-ink">${{ cards.ai_spend_today.toFixed(2) }}</p>
                <p class="text-xs text-ink-3">${{ cards.ai_spend_month.toFixed(2) }} this month</p>
            </div>
        </section>

        <section class="mt-8">
            <h2 class="font-display text-xl text-ink">Applications by status</h2>
            <ul class="mt-3 flex flex-wrap gap-3 text-sm">
                <li
                    v-for="(count, status) in cards.applications_by_status"
                    :key="status"
                    class="rounded-full border border-hairline px-3 py-1 text-ink-2"
                >
                    {{ status }}: <span class="font-medium text-ink">{{ count }}</span>
                </li>
                <li v-if="Object.keys(cards.applications_by_status).length === 0" class="text-ink-3">No applications yet.</li>
            </ul>
        </section>

        <section class="mt-8">
            <h2 class="font-display text-xl text-ink">Source health</h2>
            <table class="mt-3 w-full border-collapse text-sm">
                <thead class="text-left text-ink-3">
                    <tr>
                        <th class="py-2 pr-3">Source</th>
                        <th class="py-2 pr-3">Last fetched</th>
                        <th class="py-2 pr-3">Failures</th>
                        <th class="py-2 pr-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    <tr v-for="src in cards.sources" :key="src.id">
                        <td class="py-2 pr-3">
                            <span class="text-ink">{{ src.display_name }}</span>
                            <span class="ml-2 text-xs text-ink-3">{{ src.source_type }}{{ src.identifier ? ' / ' + src.identifier : '' }}</span>
                        </td>
                        <td class="py-2 pr-3 text-ink-3">{{ src.last_fetched_at ? new Date(src.last_fetched_at).toLocaleString() : 'never' }}</td>
                        <td class="py-2 pr-3 text-ink-3">{{ src.consecutive_failures }}</td>
                        <td class="py-2 pr-3">
                            <span :class="statusColor(src)">{{ !src.is_enabled ? 'Paused' : src.last_error ? 'Error' : 'OK' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AdminLayout>
</template>
