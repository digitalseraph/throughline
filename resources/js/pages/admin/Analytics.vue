<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { Bar, Line } from 'vue-chartjs';
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend);

defineProps<{
    ingestion: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
    matches: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
    rerankCalls: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
    aiCost: { labels: string[]; datasets: Array<{ label: string; data: number[] }> };
    funnel: Record<string, number>;
}>();

const funnelData = (funnel: Record<string, number>) => ({
    labels: Object.keys(funnel),
    datasets: [{ label: 'Applications', data: Object.values(funnel) }],
});

const baseOptions = { responsive: true, maintainAspectRatio: false };
</script>

<template>
    <Head title="Admin — Analytics" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Analytics</h1>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <section class="rounded border border-hairline bg-bone-2/40 p-4">
                <h2 class="mb-2 text-sm font-medium text-ink-2">Daily ingestion (30d)</h2>
                <div style="height: 220px"><Line :data="ingestion" :options="baseOptions" /></div>
            </section>
            <section class="rounded border border-hairline bg-bone-2/40 p-4">
                <h2 class="mb-2 text-sm font-medium text-ink-2">Daily matches (30d)</h2>
                <div style="height: 220px"><Line :data="matches" :options="baseOptions" /></div>
            </section>
            <section class="rounded border border-hairline bg-bone-2/40 p-4">
                <h2 class="mb-2 text-sm font-medium text-ink-2">Rerank calls (30d)</h2>
                <div style="height: 220px"><Line :data="rerankCalls" :options="baseOptions" /></div>
            </section>
            <section class="rounded border border-hairline bg-bone-2/40 p-4">
                <h2 class="mb-2 text-sm font-medium text-ink-2">AI spend (30d, USD)</h2>
                <div style="height: 220px"><Line :data="aiCost" :options="baseOptions" /></div>
            </section>
            <section class="rounded border border-hairline bg-bone-2/40 p-4 md:col-span-2">
                <h2 class="mb-2 text-sm font-medium text-ink-2">Application funnel</h2>
                <div style="height: 220px"><Bar :data="funnelData(funnel)" :options="baseOptions" /></div>
            </section>
        </div>
    </AdminLayout>
</template>
