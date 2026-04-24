<script setup lang="ts">
import HelpSearch from '@/components/help/HelpSearch.vue';
import HelpLayout from '@/layouts/HelpLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface Article {
    slug: string;
    title: string;
    summary: string | null;
    tags: string[];
}

defineProps<{
    siteName: string;
    category: { slug: string; title: string; summary: string | null };
    articles: Article[];
    searchIndex: Array<Article & { category: string; categoryTitle: string }>;
}>();
</script>

<template>
    <Head :title="`${category.title} — Help`" />
    <HelpLayout :site-name="siteName">
        <div class="mx-auto max-w-4xl px-6 py-10">
            <nav class="mb-4 text-sm text-ink-3">
                <Link :href="route('help.index')" class="hover:text-oxblood">Help center</Link>
                <span class="mx-2">/</span>
                <span class="text-ink-2">{{ category.title }}</span>
            </nav>

            <header class="mb-6">
                <h1 class="font-display text-4xl text-ink">{{ category.title }}</h1>
                <p v-if="category.summary" class="mt-2 text-lg text-ink-2">{{ category.summary }}</p>
            </header>

            <div class="mb-8 max-w-xl">
                <HelpSearch :index="searchIndex" />
            </div>

            <ul class="divide-y divide-hairline">
                <li v-for="article in articles" :key="article.slug" class="py-4">
                    <Link
                        :href="route('help.article', [category.slug, article.slug])"
                        class="block hover:text-oxblood"
                    >
                        <h2 class="font-display text-xl text-ink">{{ article.title }}</h2>
                        <p v-if="article.summary" class="mt-1 text-sm text-ink-2">{{ article.summary }}</p>
                        <div v-if="article.tags?.length" class="mt-2 flex flex-wrap gap-2">
                            <span
                                v-for="tag in article.tags"
                                :key="tag"
                                class="rounded-full border border-hairline px-2 py-0.5 text-[10px] uppercase tracking-wider text-ink-3"
                            >
                                {{ tag }}
                            </span>
                        </div>
                    </Link>
                </li>
            </ul>

            <div v-if="!articles.length" class="rounded-lg border border-hairline bg-bone-2 px-6 py-12 text-center text-ink-2">
                No articles in this category yet.
            </div>
        </div>
    </HelpLayout>
</template>
