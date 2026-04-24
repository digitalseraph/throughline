<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface SearchArticle {
    slug: string;
    title: string;
    summary: string | null;
    tags: string[];
    category: string;
    categoryTitle: string;
}

const props = defineProps<{
    index: SearchArticle[];
}>();

const query = ref('');

const results = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return [];

    return props.index
        .map((article) => {
            const haystack = (
                article.title +
                ' ' +
                (article.summary ?? '') +
                ' ' +
                article.tags.join(' ') +
                ' ' +
                article.categoryTitle
            ).toLowerCase();

            let score = 0;
            if (article.title.toLowerCase().includes(q)) score += 10;
            if (article.summary?.toLowerCase().includes(q)) score += 4;
            if (article.tags.some((t) => t.toLowerCase().includes(q))) score += 3;
            if (haystack.includes(q)) score += 1;
            return { ...article, score };
        })
        .filter((a) => a.score > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, 8);
});
</script>

<template>
    <div class="relative">
        <input
            v-model="query"
            type="search"
            placeholder="Search the help center…"
            class="w-full rounded-full border border-hairline bg-bone-2 px-5 py-2.5 text-sm text-ink focus:border-oxblood focus:outline-none"
        />

        <div
            v-if="query.trim() && results.length"
            class="absolute left-0 right-0 top-full z-30 mt-2 max-h-96 overflow-auto rounded-lg border border-hairline bg-bone shadow-lg"
        >
            <ul class="divide-y divide-hairline">
                <li v-for="article in results" :key="`${article.category}/${article.slug}`">
                    <Link
                        :href="route('help.article', [article.category, article.slug])"
                        class="block px-4 py-3 hover:bg-bone-2"
                    >
                        <p class="text-sm font-medium text-ink">{{ article.title }}</p>
                        <p v-if="article.summary" class="mt-0.5 text-xs text-ink-3">{{ article.summary }}</p>
                        <p class="mt-0.5 text-[11px] uppercase tracking-wider text-ink-3">{{ article.categoryTitle }}</p>
                    </Link>
                </li>
            </ul>
        </div>

        <div
            v-else-if="query.trim() && !results.length"
            class="absolute left-0 right-0 top-full z-30 mt-2 rounded-lg border border-hairline bg-bone px-4 py-3 text-sm text-ink-3 shadow-lg"
        >
            No matches for "{{ query }}". Try a different keyword, or browse the categories below.
        </div>
    </div>
</template>
