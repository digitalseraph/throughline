<script setup lang="ts">
import HelpSearch from '@/components/help/HelpSearch.vue';
import HelpLayout from '@/layouts/HelpLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Article {
    slug: string;
    title: string;
    summary: string | null;
    tags: string[];
    updated?: string | null;
    html: string;
}

interface Sibling {
    slug: string;
    title: string;
}

defineProps<{
    siteName: string;
    category: { slug: string; title: string; summary: string | null };
    article: Article;
    prev: Sibling | null;
    next: Sibling | null;
    searchIndex: Array<{ slug: string; title: string; summary: string | null; tags: string[]; category: string; categoryTitle: string }>;
}>();
</script>

<template>
    <Head :title="`${article.title} — Help`" />
    <HelpLayout :site-name="siteName">
        <div class="mx-auto max-w-4xl px-6 py-10">
            <nav class="mb-4 text-sm text-ink-3">
                <Link :href="route('help.index')" class="hover:text-oxblood">Help center</Link>
                <span class="mx-2">/</span>
                <Link :href="route('help.category', category.slug)" class="hover:text-oxblood">{{ category.title }}</Link>
                <span class="mx-2">/</span>
                <span class="text-ink-2">{{ article.title }}</span>
            </nav>

            <div class="mb-6 max-w-xl">
                <HelpSearch :index="searchIndex" />
            </div>

            <header class="mb-8">
                <h1 class="font-display text-4xl text-ink">{{ article.title }}</h1>
                <p v-if="article.summary" class="mt-2 text-lg text-ink-2">{{ article.summary }}</p>
                <p v-if="article.updated" class="mt-2 text-xs text-ink-3">Updated {{ article.updated }}</p>
                <div v-if="article.tags?.length" class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-for="tag in article.tags"
                        :key="tag"
                        class="rounded-full border border-hairline px-2 py-0.5 text-[10px] uppercase tracking-wider text-ink-3"
                    >
                        {{ tag }}
                    </span>
                </div>
            </header>

            <article class="help-prose" v-html="article.html" />

            <nav class="mt-12 flex items-center justify-between border-t border-hairline pt-6 text-sm">
                <div>
                    <Link v-if="prev" :href="route('help.article', [category.slug, prev.slug])" class="text-ink-2 hover:text-oxblood">
                        ← {{ prev.title }}
                    </Link>
                </div>
                <div>
                    <Link v-if="next" :href="route('help.article', [category.slug, next.slug])" class="text-ink-2 hover:text-oxblood">
                        {{ next.title }} →
                    </Link>
                </div>
            </nav>
        </div>
    </HelpLayout>
</template>

<style>
.help-prose {
    @apply leading-relaxed text-ink-2;
}
.help-prose h2 {
    @apply mt-10 mb-3 font-display text-2xl text-ink;
}
.help-prose h3 {
    @apply mt-8 mb-2 font-display text-xl text-ink;
}
.help-prose h4 {
    @apply mt-6 mb-2 font-display text-lg text-ink;
}
.help-prose p {
    @apply my-4;
}
.help-prose a {
    @apply text-oxblood underline hover:no-underline;
}
.help-prose ul,
.help-prose ol {
    @apply my-4 ml-6;
}
.help-prose ul {
    @apply list-disc;
}
.help-prose ol {
    @apply list-decimal;
}
.help-prose li {
    @apply my-1.5;
}
.help-prose code {
    @apply rounded bg-bone-2 px-1.5 py-0.5 font-mono text-sm text-ink;
}
.help-prose pre {
    @apply my-4 overflow-x-auto rounded-lg border border-hairline bg-bone-2 p-4;
}
.help-prose pre code {
    @apply bg-transparent p-0;
}
.help-prose blockquote {
    @apply my-4 border-l-4 border-oxblood/40 bg-bone-2/40 py-2 pl-4 italic text-ink-2;
}
.help-prose table {
    @apply my-6 w-full border-collapse text-sm;
}
.help-prose th,
.help-prose td {
    @apply border border-hairline px-3 py-2 text-left;
}
.help-prose th {
    @apply bg-bone-2 font-medium text-ink;
}
.help-prose hr {
    @apply my-8 border-hairline;
}
.help-prose img {
    @apply my-4 max-w-full rounded-lg border border-hairline;
}
.help-prose .help-anchor {
    @apply ml-2 text-ink-3 opacity-0 transition-opacity;
    text-decoration: none;
}
.help-prose h2:hover .help-anchor,
.help-prose h3:hover .help-anchor,
.help-prose h4:hover .help-anchor {
    @apply opacity-100;
}
</style>
