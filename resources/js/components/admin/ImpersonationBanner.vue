<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const impersonation = computed(() => (page.props as any).impersonation as {
    current_email: string;
    original_email: string;
    stop_url: string;
} | null);

function stopImpersonating() {
    if (impersonation.value) {
        router.post(impersonation.value.stop_url);
    }
}
</script>

<template>
    <div v-if="impersonation" class="sticky top-0 z-50 bg-oxblood px-4 py-2 text-center text-sm text-bone">
        Impersonating <strong>{{ impersonation.current_email }}</strong> (as {{ impersonation.original_email }}).
        <button class="ml-3 underline hover:no-underline" type="button" @click="stopImpersonating">
            Stop impersonating
        </button>
    </div>
</template>
