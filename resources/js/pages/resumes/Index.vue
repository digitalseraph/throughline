<script setup lang="ts">
import ResumeListItem from '@/components/resumes/ResumeListItem.vue';
import UploadDialog from '@/components/resumes/UploadDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    resumes: Array<{ id: number; name: string; parse_status: string; is_default: boolean; created_at: string }>;
}>();

const showUpload = ref(false);

function onUploaded() {
    showUpload.value = false;
    router.reload({ only: ['resumes'] });
}
</script>

<template>
    <Head title="Resumes" />
    <AppLayout>
        <div class="mx-auto max-w-3xl px-6 py-10">
            <header class="mb-8 flex items-end justify-between">
                <div>
                    <h1 class="font-display text-4xl text-ink">Your resumes</h1>
                    <p class="mt-1 text-ink-3">Different career arcs deserve different resumes.</p>
                </div>
                <button type="button" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone" @click="showUpload = true">
                    Upload resume
                </button>
            </header>

            <ul v-if="resumes.length" class="divide-y divide-hairline">
                <ResumeListItem v-for="resume in resumes" :key="resume.id" :resume="resume" />
            </ul>

            <div v-else class="rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center">
                <p class="text-ink-2">No resumes yet. Upload one to start building search profiles.</p>
            </div>

            <UploadDialog v-if="showUpload" @uploaded="onUploaded" @close="showUpload = false" />
        </div>
    </AppLayout>
</template>
