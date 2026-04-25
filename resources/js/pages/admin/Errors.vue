<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';

interface User {
    id: number;
    email: string;
}

interface FailedParse {
    id: number;
    name: string;
    parse_status: string;
    updated_at: string;
    user: User | null;
}

interface StuckResumeEmbedding {
    id: number;
    name: string;
    parse_status: string;
    updated_at: string;
}

interface StuckJobEmbedding {
    id: number;
    company_name: string;
    title: string;
    created_at: string;
}

interface RerankUnavailable {
    id: number;
    user_id: number;
    search_profile_id: number;
    canonical_job_id: number;
    stage2_at: string;
}

const props = defineProps<{
    failedParses: FailedParse[];
    stuckResumeEmbeddings: StuckResumeEmbedding[];
    stuckJobEmbeddings: StuckJobEmbedding[];
    rerankUnavailable: RerankUnavailable[];
}>();

function retryResumeParse(resume: FailedParse) {
    router.post(route('admin.errors.resume.retry-parse', resume.id), {}, { preserveScroll: true });
}

function retryResumeEmbed(resume: StuckResumeEmbedding) {
    router.post(route('admin.errors.resume.retry-embed', resume.id), {}, { preserveScroll: true });
}

function retryJobEmbed(job: StuckJobEmbedding) {
    router.post(route('admin.errors.job.retry-embed', job.id), {}, { preserveScroll: true });
}

function retryRerank(match: RerankUnavailable) {
    router.post(route('admin.errors.match.retry-rerank', match.id), {}, { preserveScroll: true });
}

function retryAllStuckJobEmbeddings() {
    if (!confirm(`Re-dispatch ${props.stuckJobEmbeddings.length}+ EmbedJob jobs?`)) return;
    router.post(route('admin.errors.job.retry-all-stuck-embeds'), {}, { preserveScroll: true });
}

function retryAllStuckResumeEmbeddings() {
    if (!confirm(`Re-dispatch ${props.stuckResumeEmbeddings.length}+ EmbedResume jobs?`)) return;
    router.post(route('admin.errors.resume.retry-all-stuck-embeds'), {}, { preserveScroll: true });
}

function formatDate(dt: string): string {
    return new Date(dt).toLocaleString();
}
</script>

<template>
    <Head title="Admin — Error Log" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Error Log</h1>
        <p class="mt-1 text-sm text-ink-3">
            Recoverable failures across the pipeline. The hourly <code>embeddings:retry-stuck</code> scheduler picks
            these up automatically; the buttons here let you nudge them sooner.
        </p>

        <!-- Failed Parses -->
        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-ink">Failed Parses ({{ props.failedParses.length }})</h2>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">User</th>
                            <th class="px-4 py-2 text-left">Updated</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-if="props.failedParses.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-ink-3">No failed parses.</td>
                        </tr>
                        <tr v-for="resume in props.failedParses" :key="resume.id" class="hover:bg-bone-2/20">
                            <td class="px-4 py-2 text-xs text-ink-3">{{ resume.id }}</td>
                            <td class="px-4 py-2 text-xs text-ink">{{ resume.name }}</td>
                            <td class="px-4 py-2 text-xs text-ink-2">{{ resume.user?.email ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ formatDate(resume.updated_at) }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90"
                                    @click="retryResumeParse(resume)"
                                >
                                    Retry parse
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Stuck Resume Embeddings -->
        <section class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-ink">
                    Stuck Resume Embeddings ({{ props.stuckResumeEmbeddings.length }})
                </h2>
                <button
                    v-if="props.stuckResumeEmbeddings.length > 0"
                    type="button"
                    class="rounded border border-hairline bg-bone px-3 py-1 text-xs font-medium text-ink hover:bg-bone-2"
                    @click="retryAllStuckResumeEmbeddings"
                >
                    Retry all stuck
                </button>
            </div>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Updated</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-if="props.stuckResumeEmbeddings.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-ink-3">No stuck resume embeddings.</td>
                        </tr>
                        <tr
                            v-for="resume in props.stuckResumeEmbeddings"
                            :key="resume.id"
                            class="hover:bg-bone-2/20"
                        >
                            <td class="px-4 py-2 text-xs text-ink-3">{{ resume.id }}</td>
                            <td class="px-4 py-2 text-xs text-ink">{{ resume.name }}</td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ formatDate(resume.updated_at) }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90"
                                    @click="retryResumeEmbed(resume)"
                                >
                                    Retry embed
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Stuck Job Embeddings -->
        <section class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-ink">
                    Stuck Job Embeddings ({{ props.stuckJobEmbeddings.length }})
                </h2>
                <button
                    v-if="props.stuckJobEmbeddings.length > 0"
                    type="button"
                    class="rounded border border-hairline bg-bone px-3 py-1 text-xs font-medium text-ink hover:bg-bone-2"
                    @click="retryAllStuckJobEmbeddings"
                >
                    Retry all stuck
                </button>
            </div>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">ID</th>
                            <th class="px-4 py-2 text-left">Title</th>
                            <th class="px-4 py-2 text-left">Company</th>
                            <th class="px-4 py-2 text-left">Created</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-if="props.stuckJobEmbeddings.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-ink-3">No stuck job embeddings.</td>
                        </tr>
                        <tr v-for="job in props.stuckJobEmbeddings" :key="job.id" class="hover:bg-bone-2/20">
                            <td class="px-4 py-2 text-xs text-ink-3">{{ job.id }}</td>
                            <td class="px-4 py-2 text-xs text-ink">{{ job.title }}</td>
                            <td class="px-4 py-2 text-xs text-ink-2">{{ job.company_name }}</td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ formatDate(job.created_at) }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90"
                                    @click="retryJobEmbed(job)"
                                >
                                    Retry embed
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Rerank Unavailable -->
        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-ink">
                Rerank Unavailable ({{ props.rerankUnavailable.length }})
            </h2>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Match ID</th>
                            <th class="px-4 py-2 text-left">Search Profile</th>
                            <th class="px-4 py-2 text-left">Job ID</th>
                            <th class="px-4 py-2 text-left">Stage2 At</th>
                            <th class="px-4 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-if="props.rerankUnavailable.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-ink-3">No rerank-unavailable matches.</td>
                        </tr>
                        <tr
                            v-for="match in props.rerankUnavailable"
                            :key="match.id"
                            class="hover:bg-bone-2/20"
                        >
                            <td class="px-4 py-2 text-xs text-ink-3">{{ match.id }}</td>
                            <td class="px-4 py-2 text-xs text-ink-2">{{ match.search_profile_id }}</td>
                            <td class="px-4 py-2 text-xs text-ink-2">{{ match.canonical_job_id }}</td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ formatDate(match.stage2_at) }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90"
                                    @click="retryRerank(match)"
                                >
                                    Retry rerank
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
