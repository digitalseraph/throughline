<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
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
import { computed, ref } from 'vue';
import { Line } from 'vue-chartjs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip, Legend);

interface LineItem {
    id: number;
    name: string;
    category: string | null;
    amount_usd: number;
    billing_cycle: 'monthly' | 'annual';
    notes: string | null;
    is_active: boolean;
    monthly_amount: number;
}

interface History {
    labels: string[];
    fixed: number[];
    ai: number[];
    total: number[];
}

const props = defineProps<{
    lineItems: LineItem[];
    fixedMonthlyUsd: number;
    aiMonthToDateUsd: number;
    aiRolling30DayUsd: number;
    currentMonthTotalUsd: number;
    thresholdUsd: number;
    thresholdLastNotifiedMonth: string;
    history: History;
}>();

const usd = (n: number) => `$${n.toFixed(2)}`;

const historyChartData = computed(() => ({
    labels: props.history.labels,
    datasets: [
        {
            label: 'Fixed',
            data: props.history.fixed,
            borderColor: '#8b7e72',
            backgroundColor: 'rgba(139, 126, 114, 0.2)',
        },
        {
            label: 'AI',
            data: props.history.ai,
            borderColor: '#a83a1a',
            backgroundColor: 'rgba(168, 58, 26, 0.2)',
        },
        {
            label: 'Total',
            data: props.history.total,
            borderColor: '#1a1410',
            backgroundColor: 'rgba(26, 20, 16, 0.1)',
            borderWidth: 2,
        },
    ],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            ticks: {
                callback: (v: number | string) => `$${v}`,
            },
        },
    },
};

const thresholdExceeded = computed(() => props.currentMonthTotalUsd >= props.thresholdUsd && props.thresholdUsd > 0);

// --- Add new line item ---
const addForm = useForm({
    name: '',
    category: '',
    amount_usd: 0,
    billing_cycle: 'monthly' as 'monthly' | 'annual',
    notes: '',
    is_active: true,
});
const showAddForm = ref(false);

function submitAdd() {
    addForm.post(route('admin.costs.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset();
            showAddForm.value = false;
        },
    });
}

// --- Edit existing ---
const editingId = ref<number | null>(null);
const editForm = useForm({
    name: '',
    category: '',
    amount_usd: 0,
    billing_cycle: 'monthly' as 'monthly' | 'annual',
    notes: '',
    is_active: true,
});

function startEdit(item: LineItem) {
    editingId.value = item.id;
    editForm.name = item.name;
    editForm.category = item.category ?? '';
    editForm.amount_usd = item.amount_usd;
    editForm.billing_cycle = item.billing_cycle;
    editForm.notes = item.notes ?? '';
    editForm.is_active = item.is_active;
}

function submitEdit(id: number) {
    editForm.patch(route('admin.costs.update', id), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function cancelEdit() {
    editingId.value = null;
    editForm.reset();
}

function destroyItem(item: LineItem) {
    if (!confirm(`Delete "${item.name}"?`)) return;
    router.delete(route('admin.costs.destroy', item.id), { preserveScroll: true });
}

// --- Threshold ---
const thresholdForm = useForm({
    threshold_usd: props.thresholdUsd,
});

function submitThreshold() {
    thresholdForm.patch(route('admin.costs.threshold'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Admin — Costs" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Infrastructure costs</h1>
        <p class="mt-1 text-sm text-ink-3">
            Fixed subscription line items + live AI spend pulled from <code>ai_usage_events</code>. Historical snapshots are written on the 1st of each month.
        </p>

        <!-- Header cards -->
        <div class="mt-6 grid gap-4 md:grid-cols-4">
            <div class="rounded border border-hairline bg-bone-2/40 p-4">
                <p class="text-xs font-medium uppercase tracking-widest text-ink-3">This month total</p>
                <p class="mt-1 font-display text-3xl text-ink">{{ usd(currentMonthTotalUsd) }}</p>
                <p v-if="thresholdExceeded" class="mt-1 text-xs font-medium text-oxblood">Over threshold ({{ usd(thresholdUsd) }})</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 p-4">
                <p class="text-xs font-medium uppercase tracking-widest text-ink-3">Fixed subscriptions</p>
                <p class="mt-1 font-display text-3xl text-ink">{{ usd(fixedMonthlyUsd) }}</p>
                <p class="mt-1 text-xs text-ink-3">per month</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 p-4">
                <p class="text-xs font-medium uppercase tracking-widest text-ink-3">AI month-to-date</p>
                <p class="mt-1 font-display text-3xl text-ink">{{ usd(aiMonthToDateUsd) }}</p>
                <p class="mt-1 text-xs text-ink-3">OpenAI + Anthropic</p>
            </div>
            <div class="rounded border border-hairline bg-bone-2/40 p-4">
                <p class="text-xs font-medium uppercase tracking-widest text-ink-3">AI rolling 30d</p>
                <p class="mt-1 font-display text-3xl text-ink">{{ usd(aiRolling30DayUsd) }}</p>
                <p class="mt-1 text-xs text-ink-3">trailing window</p>
            </div>
        </div>

        <!-- History chart -->
        <section class="mt-8 rounded border border-hairline bg-bone-2/40 p-4">
            <h2 class="mb-2 text-sm font-medium text-ink-2">Monthly history</h2>
            <p v-if="history.labels.length === 0" class="text-sm text-ink-3">No snapshots yet. The first snapshot is written on the 1st of next month.</p>
            <div v-else style="height: 260px"><Line :data="historyChartData" :options="chartOptions" /></div>
        </section>

        <!-- Threshold -->
        <section class="mt-8 rounded border border-hairline bg-bone-2/40 p-4">
            <h2 class="mb-2 text-sm font-medium text-ink-2">Alert threshold</h2>
            <p class="mb-3 text-sm text-ink-3">
                Admins get one email alert per calendar month when this month's total crosses this value. Set to 0 to disable.
            </p>
            <form class="flex items-end gap-3" @submit.prevent="submitThreshold">
                <div>
                    <label class="block text-xs font-medium uppercase tracking-wider text-ink-3">USD per month</label>
                    <input
                        v-model.number="thresholdForm.threshold_usd"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 w-32 rounded border border-hairline bg-bone px-2 py-1 text-sm"
                    />
                </div>
                <button type="submit" :disabled="thresholdForm.processing" class="rounded bg-oxblood px-4 py-1.5 text-sm font-medium text-bone hover:opacity-90 disabled:opacity-50">
                    Save
                </button>
                <span v-if="thresholdLastNotifiedMonth" class="text-xs text-ink-3">Last alert fired: {{ thresholdLastNotifiedMonth }}</span>
            </form>
        </section>

        <!-- Line items -->
        <section class="mt-8 rounded border border-hairline bg-bone-2/40 p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-medium text-ink-2">Line items</h2>
                <button
                    v-if="!showAddForm"
                    type="button"
                    class="rounded border border-hairline bg-bone px-3 py-1 text-xs font-medium text-ink hover:bg-bone-2"
                    @click="showAddForm = true"
                >
                    + Add line item
                </button>
            </div>

            <!-- Add form -->
            <form v-if="showAddForm" class="mb-4 grid gap-2 rounded border border-hairline bg-bone p-3 md:grid-cols-6" @submit.prevent="submitAdd">
                <div class="md:col-span-2">
                    <label class="block text-xs text-ink-3">Name</label>
                    <input v-model="addForm.name" type="text" required class="mt-0.5 w-full rounded border border-hairline bg-bone px-2 py-1 text-sm" />
                    <p v-if="addForm.errors.name" class="text-xs text-oxblood">{{ addForm.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-xs text-ink-3">Category</label>
                    <input v-model="addForm.category" type="text" class="mt-0.5 w-full rounded border border-hairline bg-bone px-2 py-1 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-ink-3">Amount USD</label>
                    <input v-model.number="addForm.amount_usd" type="number" step="0.01" min="0" required class="mt-0.5 w-full rounded border border-hairline bg-bone px-2 py-1 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-ink-3">Cycle</label>
                    <select v-model="addForm.billing_cycle" class="mt-0.5 w-full rounded border border-hairline bg-bone px-2 py-1 text-sm">
                        <option value="monthly">Monthly</option>
                        <option value="annual">Annual</option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" :disabled="addForm.processing" class="rounded bg-oxblood px-3 py-1 text-sm text-bone hover:opacity-90 disabled:opacity-50">Add</button>
                    <button type="button" class="rounded border border-hairline bg-bone px-3 py-1 text-sm text-ink-2" @click="showAddForm = false">Cancel</button>
                </div>
                <div class="md:col-span-6">
                    <label class="block text-xs text-ink-3">Notes</label>
                    <input v-model="addForm.notes" type="text" class="mt-0.5 w-full rounded border border-hairline bg-bone px-2 py-1 text-sm" />
                </div>
            </form>

            <!-- Line items table -->
            <table class="w-full text-sm">
                <thead class="border-b border-hairline text-xs uppercase tracking-wider text-ink-3">
                    <tr>
                        <th class="py-2 text-left">Name</th>
                        <th class="py-2 text-left">Category</th>
                        <th class="py-2 text-right">Amount</th>
                        <th class="py-2 text-left">Cycle</th>
                        <th class="py-2 text-right">Monthly</th>
                        <th class="py-2 text-left">Active</th>
                        <th class="py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="item in lineItems" :key="item.id">
                        <tr v-if="editingId !== item.id" class="border-b border-hairline/50" :class="!item.is_active && 'text-ink-3'">
                            <td class="py-2">
                                {{ item.name }}
                                <p v-if="item.notes" class="text-xs text-ink-3">{{ item.notes }}</p>
                            </td>
                            <td class="py-2 text-ink-2">{{ item.category ?? '—' }}</td>
                            <td class="py-2 text-right font-mono">{{ usd(item.amount_usd) }}</td>
                            <td class="py-2 text-ink-2">{{ item.billing_cycle }}</td>
                            <td class="py-2 text-right font-mono">{{ usd(item.monthly_amount) }}</td>
                            <td class="py-2">
                                <span v-if="item.is_active" class="inline-block rounded bg-bone-2 px-2 py-0.5 text-xs">active</span>
                                <span v-else class="inline-block rounded border border-hairline px-2 py-0.5 text-xs text-ink-3">paused</span>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" class="mr-2 text-xs text-ink-2 hover:text-oxblood" @click="startEdit(item)">Edit</button>
                                <button type="button" class="text-xs text-ink-3 hover:text-oxblood" @click="destroyItem(item)">Delete</button>
                            </td>
                        </tr>
                        <tr v-else class="border-b border-hairline bg-bone">
                            <td colspan="7" class="py-3">
                                <form class="grid gap-2 md:grid-cols-6" @submit.prevent="submitEdit(item.id)">
                                    <div class="md:col-span-2">
                                        <label class="block text-xs text-ink-3">Name</label>
                                        <input v-model="editForm.name" type="text" required class="mt-0.5 w-full rounded border border-hairline bg-bone-2 px-2 py-1 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-ink-3">Category</label>
                                        <input v-model="editForm.category" type="text" class="mt-0.5 w-full rounded border border-hairline bg-bone-2 px-2 py-1 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-ink-3">Amount USD</label>
                                        <input v-model.number="editForm.amount_usd" type="number" step="0.01" min="0" required class="mt-0.5 w-full rounded border border-hairline bg-bone-2 px-2 py-1 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs text-ink-3">Cycle</label>
                                        <select v-model="editForm.billing_cycle" class="mt-0.5 w-full rounded border border-hairline bg-bone-2 px-2 py-1 text-sm">
                                            <option value="monthly">Monthly</option>
                                            <option value="annual">Annual</option>
                                        </select>
                                    </div>
                                    <div class="flex items-end gap-2">
                                        <label class="flex items-center gap-1 text-xs text-ink-2">
                                            <input v-model="editForm.is_active" type="checkbox" />
                                            Active
                                        </label>
                                    </div>
                                    <div class="md:col-span-6">
                                        <label class="block text-xs text-ink-3">Notes</label>
                                        <input v-model="editForm.notes" type="text" class="mt-0.5 w-full rounded border border-hairline bg-bone-2 px-2 py-1 text-sm" />
                                    </div>
                                    <div class="md:col-span-6 flex gap-2">
                                        <button type="submit" :disabled="editForm.processing" class="rounded bg-oxblood px-3 py-1 text-sm text-bone hover:opacity-90 disabled:opacity-50">Save</button>
                                        <button type="button" class="rounded border border-hairline bg-bone-2 px-3 py-1 text-sm text-ink-2" @click="cancelEdit">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="lineItems.length === 0">
                        <td colspan="7" class="py-6 text-center text-sm text-ink-3">No line items yet. Click "Add line item" above.</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-hairline text-sm">
                    <tr>
                        <td colspan="4" class="py-2 text-right text-ink-3">Fixed monthly:</td>
                        <td class="py-2 text-right font-mono font-medium text-ink">{{ usd(fixedMonthlyUsd) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </section>
    </AdminLayout>
</template>
