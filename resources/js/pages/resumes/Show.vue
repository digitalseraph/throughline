<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps<{
    resume: {
        id: number;
        name: string;
        parse_status: string;
        is_default: boolean;
        has_embedding: boolean;
        parsed_text: string | null;
        structured_json: Record<string, any> | null;
        created_at: string;
    };
}>();

let pollHandle: number | undefined;

function isTerminalState(): boolean {
    // parse_failed is terminal; parsed + has_embedding is the ready state.
    return props.resume.parse_status === 'parse_failed' || (props.resume.parse_status === 'parsed' && props.resume.has_embedding);
}

function startPolling(): void {
    pollHandle = window.setInterval(() => {
        router.reload({
            only: ['resume'],
            onSuccess: () => {
                if (isTerminalState() && pollHandle) {
                    clearInterval(pollHandle);
                    pollHandle = undefined;
                }
            },
        });
    }, 3000);
}

onMounted(() => {
    if (!isTerminalState()) {
        startPolling();
    }
});

onUnmounted(() => {
    if (pollHandle) clearInterval(pollHandle);
});

function rename() {
    const name = window.prompt('Rename resume:', props.resume.name);
    if (name && name.trim()) {
        router.patch(route('resumes.update', props.resume.id), { name });
    }
}

function setDefault() {
    router.post(route('resumes.default', props.resume.id));
}

function retry() {
    router.post(route('resumes.retry', props.resume.id));
}

function destroy() {
    if (window.confirm('Delete this resume? This cannot be undone.')) {
        router.delete(route('resumes.destroy', props.resume.id));
    }
}
</script>

<template>
    <Head :title="resume.name" />
    <AppLayout>
        <div class="mx-auto max-w-3xl px-6 py-10">
            <Link :href="route('resumes.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All resumes</Link>
            <header class="mt-4 flex items-start justify-between">
                <h1 class="font-display text-4xl text-ink">{{ resume.name }}</h1>
                <span v-if="resume.is_default" class="rounded-full border border-oxblood px-3 py-1 text-xs font-medium text-oxblood">Default</span>
            </header>

            <section class="mt-8 rounded-lg border border-hairline bg-bone-2 px-6 py-5">
                <p class="text-sm text-ink-2">
                    <span v-if="resume.parse_status === 'pending'">Parsing your resume. This usually takes under a minute…</span>
                    <span v-else-if="resume.parse_status === 'parse_failed'">Parse failed. You can paste the text manually or retry.</span>
                    <span v-else-if="!resume.has_embedding">Parsed. Generating embedding…</span>
                    <span v-else>Ready to match against jobs.</span>
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button v-if="!resume.is_default" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="setDefault">
                        Set as default
                    </button>
                    <button class="rounded border border-ink-3 px-3 py-1 text-sm" @click="rename">Rename</button>
                    <button
                        v-if="resume.parse_status === 'parse_failed'"
                        class="rounded border border-oxblood px-3 py-1 text-sm text-oxblood"
                        @click="retry"
                    >
                        Retry parse
                    </button>
                    <button class="rounded border border-ink-3 px-3 py-1 text-sm text-ink-3" @click="destroy">Delete</button>
                </div>
            </section>

            <section v-if="resume.structured_json" class="mt-8">
                <h2 class="font-display text-xl text-ink">Extracted highlights</h2>
                <pre class="mt-3 overflow-x-auto rounded border border-hairline bg-bone-2 p-4 text-xs text-ink-2">{{
                    JSON.stringify(resume.structured_json, null, 2)
                }}</pre>
            </section>

            <section v-if="resume.parsed_text" class="mt-8">
                <h2 class="font-display text-xl text-ink">Plain-text content</h2>
                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap rounded border border-hairline bg-bone-2 p-4 text-xs text-ink-2">{{
                    resume.parsed_text
                }}</pre>
            </section>
        </div>
    </AppLayout>
</template>
