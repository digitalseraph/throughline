<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { echo } from '@/echo';

defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();

// Live badge count — supersedes the SSR prop once a push arrives.
const liveBadgeCount = ref<number | null>(null);
const badgeCount = computed(() =>
    liveBadgeCount.value !== null
        ? liveBadgeCount.value
        : (page.props.reminderBadgeCount as number),
);

let channel: ReturnType<typeof echo.private> | null = null;

onMounted(() => {
    const authId = (page.props.auth as { user?: { id?: number } })?.user?.id;
    if (!authId) return;

    channel = echo.private(`users.${authId}`);
    channel.listen('.ReminderBadgeUpdated', (event: { count: number }) => {
        liveBadgeCount.value = event.count;
    });
});

onUnmounted(() => {
    if (channel) {
        channel.stopListening('.ReminderBadgeUpdated');
        channel = null;
    }
    const authId = (page.props.auth as { user?: { id?: number } })?.user?.id;
    if (authId) {
        echo.leave(`users.${authId}`);
    }
});
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="item.href === page.url">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <span
                            v-if="item.href === '/applications' && badgeCount > 0"
                            class="ml-auto rounded-full bg-oxblood px-2 py-0.5 text-[10px] font-medium text-bone"
                        >
                            {{ badgeCount }}
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
