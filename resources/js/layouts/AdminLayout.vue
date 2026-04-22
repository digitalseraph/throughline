<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const navItems = [
    { title: 'Overview', href: '/admin', icon: '◔' },
    { title: 'Users', href: '/admin/users', icon: '◑' },
    { title: 'Jobs', href: '/admin/jobs', icon: '◐' },
    { title: 'Sources', href: '/admin/job-sources', icon: '◒' },
    { title: 'Analytics', href: '/admin/analytics', icon: '◕' },
    { title: 'Settings', href: '/admin/settings', icon: '●' },
    { title: 'Audit', href: '/admin/audit', icon: '◉' },
    { title: 'Errors', href: '/admin/errors', icon: '◎' },
    { title: 'Horizon', href: '/admin/horizon', icon: '◯' },
];

const page = usePage();
const currentPath = computed(() => page.url);
</script>

<template>
    <AppLayout>
        <div class="mx-auto flex w-full max-w-7xl gap-6 px-6 py-6">
            <aside class="w-48 shrink-0">
                <h2 class="mb-3 text-xs font-medium uppercase tracking-wider text-ink-3">Admin</h2>
                <nav class="space-y-1">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        :class="[
                            'block rounded px-2 py-1 text-sm',
                            currentPath.startsWith(item.href)
                                ? 'bg-bone-2 font-medium text-ink'
                                : 'text-ink-2 hover:bg-bone-2/60 hover:text-ink',
                        ]"
                    >
                        <span class="mr-2 text-ink-3">{{ item.icon }}</span>{{ item.title }}
                    </Link>
                </nav>
            </aside>

            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>
    </AppLayout>
</template>
