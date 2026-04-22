<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ApplicationEvent {
    id: number;
    type: string;
    payload: Record<string, unknown>;
    occurred_at: string;
}

interface ApplicationDetail {
    id: number;
    status: string;
    notes: string | null;
    applied_at: string | null;
    next_action_at: string | null;
    stage2_snapshot: Record<string, unknown> | null;
    is_terminal: boolean;
    canonical_job: {
        id: number;
        title: string;
        company_name: string;
        location_raw: string | null;
        apply_url: string | null;
    } | null;
    resume: { id: number; name: string } | null;
    events: ApplicationEvent[];
    contacts: Array<{ id: number; name: string; role: string }>;
    documents: Array<{ id: number; kind: string; label: string }>;
}

const props = defineProps<{
    application: ApplicationDetail;
    statuses: string[];
    terminalStatuses: string[];
}>();

const notesForm = useForm({
    notes: props.application.notes ?? '',
});

const statusForm = useForm({
    status: props.application.status,
});

const showWithdrawConfirm = ref(false);

function submitNotes() {
    notesForm.patch(route('applications.update', props.application.id), {
        preserveScroll: true,
    });
}

function submitStatus() {
    statusForm.patch(route('applications.update', props.application.id), {
        preserveScroll: true,
    });
}

function withdraw() {
    useForm({}).delete(route('applications.destroy', props.application.id));
}

function labelFor(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function eventLabel(event: ApplicationEvent): string {
    if (event.type === 'status_change') {
        const from = (event.payload.from as string | null) ?? '—';
        const to = event.payload.to as string;
        return `Status: ${labelFor(from)} → ${labelFor(to)}`;
    }
    if (event.type === 'note') {
        return `Note: ${event.payload.body as string}`;
    }
    return `${labelFor(event.type)}`;
}
</script>

<template>
    <Head :title="application.canonical_job?.title ?? 'Application'" />
    <AppLayout>
        <div class="mx-auto max-w-2xl px-6 py-10">
            <Link :href="route('applications.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All applications</Link>

            <header class="mt-4 mb-8">
                <h1 class="font-display text-4xl text-ink">
                    {{ application.canonical_job?.title ?? '—' }}
                </h1>
                <p class="mt-1 text-ink-3">
                    {{ application.canonical_job?.company_name ?? '—' }}
                    <span v-if="application.canonical_job?.location_raw"> · {{ application.canonical_job.location_raw }}</span>
                </p>
                <div class="mt-2 flex items-center gap-3">
                    <span class="inline-block rounded-full bg-bone-2 px-3 py-0.5 text-xs font-medium text-ink-2">
                        {{ labelFor(application.status) }}
                    </span>
                    <a
                        v-if="application.canonical_job?.apply_url"
                        :href="application.canonical_job.apply_url"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-oxblood underline"
                    >Apply link</a>
                </div>
            </header>

            <!-- Status change -->
            <section v-if="!application.is_terminal" class="mb-8">
                <h2 class="mb-2 text-sm font-semibold text-ink">Move to stage</h2>
                <form @submit.prevent="submitStatus" class="flex gap-2">
                    <select
                        v-model="statusForm.status"
                        class="flex-1 rounded border border-hairline bg-white px-3 py-2 text-sm text-ink"
                    >
                        <option v-for="s in statuses" :key="s" :value="s">{{ labelFor(s) }}</option>
                    </select>
                    <button
                        type="submit"
                        :disabled="statusForm.processing || statusForm.status === application.status"
                        class="rounded bg-oxblood px-4 py-2 text-sm font-medium text-bone disabled:opacity-50"
                    >Update</button>
                </form>
            </section>

            <!-- Notes -->
            <section class="mb-8">
                <h2 class="mb-2 text-sm font-semibold text-ink">Notes</h2>
                <form @submit.prevent="submitNotes">
                    <textarea
                        v-model="notesForm.notes"
                        rows="4"
                        placeholder="Private notes about this application…"
                        class="w-full rounded border border-hairline bg-white px-3 py-2 text-sm text-ink placeholder:text-ink-3"
                    ></textarea>
                    <div class="mt-2 flex justify-end">
                        <button
                            type="submit"
                            :disabled="notesForm.processing"
                            class="rounded bg-oxblood px-4 py-2 text-sm font-medium text-bone disabled:opacity-50"
                        >Save notes</button>
                    </div>
                </form>
            </section>

            <!-- Event timeline -->
            <section class="mb-8">
                <h2 class="mb-2 text-sm font-semibold text-ink">Timeline</h2>
                <ul v-if="application.events.length" class="space-y-2">
                    <li
                        v-for="event in application.events"
                        :key="event.id"
                        class="flex items-start gap-3 text-sm"
                    >
                        <span class="mt-0.5 text-xs text-ink-3 whitespace-nowrap">{{ event.occurred_at.slice(0, 16).replace('T', ' ') }}</span>
                        <span class="text-ink">{{ eventLabel(event) }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-3">No events yet.</p>
            </section>

            <!-- Withdraw -->
            <section v-if="!application.is_terminal" class="border-t border-hairline pt-6">
                <div v-if="!showWithdrawConfirm">
                    <button
                        type="button"
                        class="text-sm text-red-600 underline"
                        @click="showWithdrawConfirm = true"
                    >Withdraw application</button>
                </div>
                <div v-else class="flex items-center gap-4">
                    <p class="text-sm text-ink">Are you sure? This marks the application as withdrawn.</p>
                    <button type="button" class="rounded bg-red-600 px-3 py-1.5 text-sm text-white" @click="withdraw">Yes, withdraw</button>
                    <button type="button" class="text-sm text-ink-3" @click="showWithdrawConfirm = false">Cancel</button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
