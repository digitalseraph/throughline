<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Admin {
    id: number;
    email: string;
}

interface AuditLog {
    id: number;
    admin: Admin | null;
    action: string;
    target_type: string | null;
    target_id: number | null;
    payload: Record<string, unknown> | null;
    ip: string;
    created_at: string;
}

interface PaginatedLogs {
    data: AuditLog[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    action?: string;
    from?: string;
    to?: string;
}

const props = defineProps<{
    logs: PaginatedLogs;
    filters: Filters;
}>();

const action = ref(props.filters.action ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

function applyFilters() {
    router.get(
        route('admin.audit.index'),
        { action: action.value, from: from.value, to: to.value },
        { preserveState: true, replace: true },
    );
}

function clearFilters() {
    action.value = '';
    from.value = '';
    to.value = '';
    applyFilters();
}

function payloadSnippet(payload: Record<string, unknown> | null): string {
    if (!payload || Object.keys(payload).length === 0) return '—';
    const json = JSON.stringify(payload);
    return json.length > 80 ? json.slice(0, 77) + '…' : json;
}

function formatDate(dt: string): string {
    return new Date(dt).toLocaleString();
}
</script>

<template>
    <Head title="Admin — Audit Log" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Audit Log</h1>

        <!-- Filters -->
        <section class="mt-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-ink-3">Action contains</label>
                <input
                    v-model="action"
                    type="text"
                    placeholder="e.g. role_changed"
                    class="rounded border border-hairline px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-brand"
                    @keydown.enter="applyFilters"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-ink-3">From</label>
                <input
                    v-model="from"
                    type="date"
                    class="rounded border border-hairline px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-brand"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-ink-3">To</label>
                <input
                    v-model="to"
                    type="date"
                    class="rounded border border-hairline px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-brand"
                />
            </div>
            <button
                class="rounded bg-brand px-4 py-1.5 text-sm font-medium text-white hover:bg-brand/90"
                @click="applyFilters"
            >
                Filter
            </button>
            <button
                class="rounded border border-hairline px-4 py-1.5 text-sm font-medium text-ink-2 hover:bg-bone-2/30"
                @click="clearFilters"
            >
                Clear
            </button>
        </section>

        <!-- Table -->
        <div class="mt-6 overflow-hidden rounded border border-hairline">
            <table class="w-full text-sm">
                <thead class="bg-bone-2/60 text-xs uppercase text-ink-3">
                    <tr>
                        <th class="px-4 py-2 text-left">Admin</th>
                        <th class="px-4 py-2 text-left">Action</th>
                        <th class="px-4 py-2 text-left">Target</th>
                        <th class="px-4 py-2 text-left">IP</th>
                        <th class="px-4 py-2 text-left">Payload</th>
                        <th class="px-4 py-2 text-left">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    <tr v-if="logs.data.length === 0">
                        <td colspan="6" class="px-4 py-6 text-center text-ink-3">No audit entries found.</td>
                    </tr>
                    <tr v-for="log in logs.data" :key="log.id" class="bg-white hover:bg-bone-2/20">
                        <td class="px-4 py-2 text-xs text-ink-2">{{ log.admin?.email ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-ink">{{ log.action }}</td>
                        <td class="px-4 py-2 text-xs text-ink-3">
                            <template v-if="log.target_type">{{ log.target_type }} #{{ log.target_id }}</template>
                            <template v-else>—</template>
                        </td>
                        <td class="px-4 py-2 text-xs text-ink-3">{{ log.ip }}</td>
                        <td class="max-w-xs truncate px-4 py-2 font-mono text-xs text-ink-3">
                            {{ payloadSnippet(log.payload) }}
                        </td>
                        <td class="px-4 py-2 text-xs text-ink-3">{{ formatDate(log.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="logs.last_page > 1" class="mt-4 flex items-center gap-3 text-sm">
            <a
                v-if="logs.prev_page_url"
                :href="logs.prev_page_url"
                class="rounded border border-hairline px-3 py-1 text-ink-2 hover:bg-bone-2/30"
            >
                &larr; Prev
            </a>
            <span class="text-ink-3">Page {{ logs.current_page }} of {{ logs.last_page }}</span>
            <a
                v-if="logs.next_page_url"
                :href="logs.next_page_url"
                class="rounded border border-hairline px-3 py-1 text-ink-2 hover:bg-bone-2/30"
            >
                Next &rarr;
            </a>
        </div>
    </AdminLayout>
</template>
