<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    siteName: string;
}>();

const page = usePage();
const authUser = computed<{ id: number; name: string; email: string } | null>(
    () => (page.props as any).auth?.user ?? null,
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-bone">
        <header class="border-b border-hairline bg-bone">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                <Link href="/" class="font-display text-xl font-semibold text-ink hover:text-oxblood">
                    {{ siteName }}
                </Link>

                <nav class="flex items-center gap-6 text-sm">
                    <Link :href="route('about')" class="text-ink-2 hover:text-oxblood">About</Link>
                    <Link :href="route('help.index')" class="text-ink-2 hover:text-oxblood">Help</Link>
                    <template v-if="authUser">
                        <Link :href="route('dashboard')" class="text-ink-2 hover:text-oxblood">Dashboard</Link>
                        <span class="text-ink-3">Signed in as {{ authUser.name }}</span>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" class="text-ink-2 hover:text-oxblood">Log in</Link>
                        <Link
                            :href="route('register')"
                            class="rounded-full bg-oxblood px-4 py-1.5 text-sm font-medium text-bone hover:bg-oxblood/90"
                        >
                            Get started
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-hairline bg-bone-2 py-8 text-sm text-ink-3">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6">
                <p>&copy; {{ new Date().getFullYear() }} {{ siteName }}</p>
                <nav class="flex gap-6">
                    <Link :href="route('about')" class="hover:text-oxblood">About</Link>
                    <Link :href="route('help.index')" class="hover:text-oxblood">Help</Link>
                    <a href="https://github.com/digitalseraph/throughline" target="_blank" rel="noopener" class="hover:text-oxblood">Source</a>
                </nav>
            </div>
        </footer>
    </div>
</template>
