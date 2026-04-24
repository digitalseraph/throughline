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

interface Category {
    slug: string;
    title: string;
    summary: string | null;
    order: number;
    articles: Article[];
}

interface SearchArticle extends Article {
    category: string;
    categoryTitle: string;
}

defineProps<{
    siteName: string;
    categories: Category[];
    searchIndex: SearchArticle[];
}>();
</script>

<template>
    <Head title="Help center" />
    <HelpLayout :site-name="siteName">
        <section class="mx-auto max-w-4xl px-6 pt-16 pb-10 text-center">
            <h1 class="font-display text-5xl text-ink">Help center</h1>
            <p class="mt-4 text-lg text-ink-2">
                Everything you need to find roles that fit the arc of your career — tutorials, how-tos, and answers.
            </p>
            <div class="mx-auto mt-8 max-w-xl">
                <HelpSearch :index="searchIndex" />
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-6 pb-20">
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="category in categories"
                    :key="category.slug"
                    class="rounded-lg border border-hairline bg-bone-2/40 p-5 hover:border-oxblood"
                >
                    <h2 class="font-display text-xl text-ink">
                        <Link :href="route('help.category', category.slug)" class="hover:text-oxblood">{{ category.title }}</Link>
                    </h2>
                    <p v-if="category.summary" class="mt-1 text-sm text-ink-3">{{ category.summary }}</p>
                    <ul class="mt-4 space-y-1.5 text-sm">
                        <li v-for="article in category.articles.slice(0, 5)" :key="article.slug">
                            <Link
                                :href="route('help.article', [category.slug, article.slug])"
                                class="text-ink-2 hover:text-oxblood"
                            >
                                {{ article.title }}
                            </Link>
                        </li>
                        <li v-if="category.articles.length > 5" class="mt-2">
                            <Link
                                :href="route('help.category', category.slug)"
                                class="text-xs text-ink-3 hover:text-oxblood"
                            >
                                + {{ category.articles.length - 5 }} more →
                            </Link>
                        </li>
                    </ul>
                </article>
            </div>
        </section>
    </HelpLayout>
</template>
