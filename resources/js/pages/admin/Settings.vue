<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Setting {
    key: string;
    value: string | null;
    type: 'string' | 'int' | 'bool' | 'json';
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

// Per-key edit buffer. Booleans normalize to 'true'/'false' strings (the
// backend validates against those literals).
const editValues = ref<Record<string, string>>(
    Object.fromEntries(
        props.settings.map((s) => [s.key, normalize(s.value, s.type)]),
    ),
);

const saving = ref<Record<string, boolean>>({});
const saved = ref<Record<string, boolean>>({});
const errors = ref<Record<string, string>>({});

const grouped = computed(() => ({
    theming: props.settings.filter((s) => s.key.startsWith('brand_')),
    general: props.settings.filter((s) => !s.key.startsWith('brand_')),
}));

function normalize(value: string | null, type: Setting['type']): string {
    if (type === 'bool') {
        const v = String(value ?? '').toLowerCase();
        return v === 'true' || v === '1' ? 'true' : 'false';
    }
    return value ?? '';
}

function isDirty(setting: Setting): boolean {
    return editValues.value[setting.key] !== normalize(setting.value, setting.type);
}

function save(setting: Setting) {
    saving.value[setting.key] = true;
    saved.value[setting.key] = false;
    errors.value[setting.key] = '';

    router.patch(
        route('admin.settings.update', setting.key),
        { value: editValues.value[setting.key] },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved.value[setting.key] = true;
                setTimeout(() => (saved.value[setting.key] = false), 2000);
            },
            onError: (formErrors) => {
                errors.value[setting.key] = (formErrors.value as string) || 'Save failed.';
            },
            onFinish: () => {
                saving.value[setting.key] = false;
            },
        },
    );
}

function toggleBool(setting: Setting) {
    editValues.value[setting.key] = editValues.value[setting.key] === 'true' ? 'false' : 'true';
    save(setting);
}
</script>

<template>
    <Head title="Admin — Settings" />
    <AdminLayout>
        <h1 class="font-display text-3xl text-ink">Settings</h1>
        <p class="mt-1 text-sm text-ink-3">
            Edit per-install configuration stored in <code>site_settings</code>. Changes apply on save and invalidate the Redis cache automatically.
        </p>

        <section class="mt-8">
            <h2 class="mb-3 text-lg font-semibold text-ink">General</h2>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Key</th>
                            <th class="px-4 py-2 text-left" style="min-width: 280px">Value</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Description</th>
                            <th class="px-4 py-2 text-right">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="setting in grouped.general" :key="setting.key" class="align-top hover:bg-bone-2/20">
                            <td class="px-4 py-3 font-mono text-xs text-ink-2">{{ setting.key }}</td>
                            <td class="px-4 py-3">
                                <!-- BOOL: switch (auto-saves on toggle) -->
                                <button
                                    v-if="setting.type === 'bool'"
                                    type="button"
                                    role="switch"
                                    :aria-checked="editValues[setting.key] === 'true'"
                                    :disabled="saving[setting.key]"
                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-oxblood/40 disabled:opacity-50"
                                    :class="editValues[setting.key] === 'true' ? 'bg-oxblood' : 'bg-hairline'"
                                    @click="toggleBool(setting)"
                                >
                                    <span
                                        class="inline-block h-4 w-4 transform rounded-full bg-bone shadow transition-transform"
                                        :class="editValues[setting.key] === 'true' ? 'translate-x-4' : 'translate-x-0.5'"
                                    />
                                </button>
                                <!-- INT -->
                                <input
                                    v-else-if="setting.type === 'int'"
                                    v-model="editValues[setting.key]"
                                    type="number"
                                    step="1"
                                    class="w-32 rounded border border-hairline bg-bone px-2 py-1 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40"
                                />
                                <!-- JSON -->
                                <textarea
                                    v-else-if="setting.type === 'json'"
                                    v-model="editValues[setting.key]"
                                    rows="3"
                                    class="w-full rounded border border-hairline bg-bone px-2 py-1 font-mono text-xs focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40"
                                />
                                <!-- STRING (default) -->
                                <input
                                    v-else
                                    v-model="editValues[setting.key]"
                                    type="text"
                                    class="w-full rounded border border-hairline bg-bone px-2 py-1 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40"
                                />
                                <p v-if="errors[setting.key]" class="mt-1 text-xs text-oxblood">{{ errors[setting.key] }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-3">{{ setting.type }}</td>
                            <td class="px-4 py-3 text-xs text-ink-3">{{ setting.description }}</td>
                            <td class="px-4 py-3 text-right">
                                <!-- Bool auto-saves; show inline status only -->
                                <span v-if="setting.type === 'bool'" class="text-xs text-ink-3">
                                    <span v-if="saving[setting.key]">Saving…</span>
                                    <span v-else-if="saved[setting.key]" class="text-green-700">Saved</span>
                                </span>
                                <button
                                    v-else
                                    type="button"
                                    :disabled="saving[setting.key] || !isDirty(setting)"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90 disabled:opacity-40"
                                    @click="save(setting)"
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
            <h2 class="mb-1 text-lg font-semibold text-ink">Theming</h2>
            <p class="mb-3 text-xs text-ink-3">Leave blank to use the default. Some changes (font-family) require a hard refresh to take effect on already-loaded pages.</p>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Key</th>
                            <th class="px-4 py-2 text-left" style="min-width: 280px">Value</th>
                            <th class="px-4 py-2 text-left">Type</th>
                            <th class="px-4 py-2 text-left">Description</th>
                            <th class="px-4 py-2 text-right">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="setting in grouped.theming" :key="setting.key" class="align-top hover:bg-bone-2/20">
                            <td class="px-4 py-3 font-mono text-xs text-ink-2">{{ setting.key }}</td>
                            <td class="px-4 py-3">
                                <textarea
                                    v-if="setting.type === 'json'"
                                    v-model="editValues[setting.key]"
                                    rows="3"
                                    class="w-full rounded border border-hairline bg-bone px-2 py-1 font-mono text-xs focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40"
                                />
                                <input
                                    v-else
                                    v-model="editValues[setting.key]"
                                    type="text"
                                    class="w-full rounded border border-hairline bg-bone px-2 py-1 text-sm focus:border-oxblood focus:outline-none focus:ring-1 focus:ring-oxblood/40"
                                />
                                <p v-if="errors[setting.key]" class="mt-1 text-xs text-oxblood">{{ errors[setting.key] }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-ink-3">{{ setting.type }}</td>
                            <td class="px-4 py-3 text-xs text-ink-3">{{ setting.description }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    type="button"
                                    :disabled="saving[setting.key] || !isDirty(setting)"
                                    class="rounded bg-oxblood px-3 py-1 text-xs font-medium text-bone hover:opacity-90 disabled:opacity-40"
                                    @click="save(setting)"
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
            <h2 class="mb-3 text-lg font-semibold text-ink">
                Environment-sourced secrets <span class="text-sm font-normal text-ink-3">(read-only)</span>
            </h2>
            <p class="mb-3 text-xs text-ink-3">
                These come from <code>.env</code>, not the database — change them in the deploy environment and redeploy.
            </p>
            <div class="overflow-hidden rounded border border-hairline bg-bone">
                <table class="w-full text-sm">
                    <thead class="border-b border-hairline bg-bone-2/40 text-xs uppercase tracking-wider text-ink-3">
                        <tr>
                            <th class="px-4 py-2 text-left">Key</th>
                            <th class="px-4 py-2 text-left">Value</th>
                            <th class="px-4 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        <tr v-for="secret in secrets" :key="secret.key" class="hover:bg-bone-2/20">
                            <td class="px-4 py-2 font-mono text-xs text-ink-2">{{ secret.key }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-ink-3">{{ secret.masked }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="inline-block rounded px-2 py-0.5 text-xs font-medium"
                                    :class="secret.is_set ? 'bg-bone-2 text-ink' : 'border border-hairline text-ink-3'"
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
