<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string[];
        placeholder?: string;
        suggestions?: string[];
        maxSuggestions?: number;
    }>(),
    {
        placeholder: 'Type and press Enter…',
        suggestions: () => [],
        maxSuggestions: 6,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

const draft = ref('');
const inputEl = ref<HTMLInputElement | null>(null);
const focused = ref(false);
const highlightIdx = ref(-1);

const tags = computed(() => props.modelValue);

const filteredSuggestions = computed(() => {
    const q = draft.value.trim().toLowerCase();
    if (q === '') return [];
    const used = new Set(tags.value.map((t) => t.toLowerCase()));
    return props.suggestions
        .filter((s) => !used.has(s.toLowerCase()) && s.toLowerCase().includes(q))
        .slice(0, props.maxSuggestions);
});

function addTag(value: string) {
    const v = value.trim();
    if (v === '') return;
    if (tags.value.some((t) => t.toLowerCase() === v.toLowerCase())) {
        draft.value = '';
        return;
    }
    emit('update:modelValue', [...tags.value, v]);
    draft.value = '';
    highlightIdx.value = -1;
}

function removeTag(index: number) {
    const next = [...tags.value];
    next.splice(index, 1);
    emit('update:modelValue', next);
}

function onKeydown(e: KeyboardEvent) {
    const sugs = filteredSuggestions.value;

    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        if (highlightIdx.value >= 0 && sugs[highlightIdx.value]) {
            addTag(sugs[highlightIdx.value]);
        } else {
            addTag(draft.value);
        }
        return;
    }
    if (e.key === 'Tab' && sugs.length > 0 && draft.value.trim() !== '') {
        e.preventDefault();
        addTag(sugs[Math.max(0, highlightIdx.value)]);
        return;
    }
    if (e.key === 'Backspace' && draft.value === '' && tags.value.length > 0) {
        removeTag(tags.value.length - 1);
        return;
    }
    if (e.key === 'ArrowDown' && sugs.length > 0) {
        e.preventDefault();
        highlightIdx.value = (highlightIdx.value + 1) % sugs.length;
        return;
    }
    if (e.key === 'ArrowUp' && sugs.length > 0) {
        e.preventDefault();
        highlightIdx.value = highlightIdx.value <= 0 ? sugs.length - 1 : highlightIdx.value - 1;
        return;
    }
    if (e.key === 'Escape') {
        highlightIdx.value = -1;
        draft.value = '';
    }
}

function onBlur() {
    // Slight delay so a click on a suggestion can register before we hide them.
    setTimeout(() => {
        focused.value = false;
        // Don't auto-commit a half-typed draft on blur; let the user explicitly
        // press Enter/comma/Tab. This avoids the old "comma gets eaten" bug.
    }, 100);
}

function focus() {
    nextTick(() => inputEl.value?.focus());
}

defineExpose({ focus });
</script>

<template>
    <div
        class="flex min-h-[42px] flex-wrap items-center gap-1.5 rounded border border-hairline bg-bone px-2 py-1.5 text-sm focus-within:border-oxblood focus-within:ring-1 focus-within:ring-oxblood/40"
        @click="focus"
    >
        <span
            v-for="(tag, i) in tags"
            :key="`${tag}-${i}`"
            class="inline-flex items-center gap-1 rounded-full bg-bone-2 px-2.5 py-0.5 text-xs text-ink"
        >
            {{ tag }}
            <button
                type="button"
                class="text-ink-3 hover:text-oxblood"
                aria-label="Remove tag"
                @click.stop="removeTag(i)"
            >
                ×
            </button>
        </span>

        <div class="relative min-w-[140px] flex-1">
            <input
                ref="inputEl"
                v-model="draft"
                type="text"
                :placeholder="tags.length === 0 ? placeholder : ''"
                class="w-full bg-transparent px-1 py-0.5 text-sm focus:outline-none"
                @keydown="onKeydown"
                @focus="focused = true"
                @blur="onBlur"
            />

            <ul
                v-if="focused && filteredSuggestions.length > 0"
                class="absolute left-0 top-full z-10 mt-1 max-h-56 w-full min-w-[180px] overflow-auto rounded border border-hairline bg-bone shadow-lg"
            >
                <li
                    v-for="(s, i) in filteredSuggestions"
                    :key="s"
                    class="cursor-pointer px-3 py-1.5 text-sm hover:bg-bone-2"
                    :class="i === highlightIdx ? 'bg-bone-2' : ''"
                    @mousedown.prevent="addTag(s)"
                    @mouseenter="highlightIdx = i"
                >
                    {{ s }}
                </li>
            </ul>
        </div>
    </div>
</template>
