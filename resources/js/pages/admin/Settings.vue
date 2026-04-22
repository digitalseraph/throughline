<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Setting {
    key: string;
    value: string | null;
    type: string;
    description: string | null;
}

interface Secret {
    key: string;
    masked: string;
    is_set: boolean;
}

const props = defineProps<{
    settings: Setting[];
    secrets: Secret[];
}>();

const editValues = ref<Record<string, string>>(
    Object.fromEntries(props.settings.map((s) => [s.key, s.value ?? ''])),
);

const saving = ref<Record<string, boolean>>({});
const saved = ref<Record<string, boolean>>({});

function save(key: string) {
    saving.value[key] = true;
    saved.value[key] = false;

    router.patch(
        route('admin.settings.update', key),
        { value: editValues.value[key] },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value[key] = true;
                setTimeout(() => (saved.value[key] = false), 2000);
            },
            onFinish: () => {
                saving.value[key] = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Admin — Settings" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Settings</h1>

        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-ink">Site settings</h2>
            <div class="overflow-hidden rounded border border-hairline">
                <table class="w-full text-sm">
                    <thead class="bg-bone-2/60 text-xs uppercase text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Key</th>
                            <th class="px-4 py-2 text-left">Value</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Description</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="setting in settings" :key="setting.key" class="bg-white hover:bg-bone-2/20">
                            <td class="px-4 py-2 font-mono text-xs text-ink-2">{{ setting.key }}</td>
                            <td class="px-4 py-2">
                                <input
                                    v-model="editValues[setting.key]"
                                    type="text"
                                    class="w-full rounded border border-hairline px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-brand"
                                />
                            </td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ setting.type }}</td>
                            <td class="px-4 py-2 text-xs text-ink-3">{{ setting.description }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    class="rounded bg-brand px-3 py-1 text-xs font-medium text-white hover:bg-brand/90 disabled:opacity-50"
                                    :disabled="saving[setting.key]"
                                    @click="save(setting.key)"
                                >
                                    {{ saving[setting.key] ? 'Saving…' : saved[setting.key] ? 'Saved' : 'Save' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-10">
            <h2 class="mb-3 text-lg font-semibold text-ink">Environment-sourced secrets <span class="text-sm font-normal text-ink-3">(read-only)</span></h2>
            <div class="overflow-hidden rounded border border-hairline">
                <table class="w-full text-sm">
                    <thead class="bg-bone-2/60 text-xs uppercase text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Key</th>
                            <th class="px-4 py-2 text-left">Value</th>
                            <th class="px-4 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="secret in secrets" :key="secret.key" class="bg-white">
                            <td class="px-4 py-2 font-mono text-xs text-ink-2">{{ secret.key }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-ink-3">{{ secret.masked }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="secret.is_set ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700'"
                                >
                                    {{ secret.is_set ? 'Set' : 'Not set' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
