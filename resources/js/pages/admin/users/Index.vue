<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
  users: { data: Array<{ id: number; name: string; email: string; role: string; last_login_at: string | null; deactivated_at: string | null; resumes_count: number; applications_count: number }>; links: any[] };
  filters: { q: string };
}>();

const q = ref(props.filters.q);

function search() {
  router.get(route('admin.users.index'), { q: q.value }, { preserveState: true, replace: true });
}
</script>

<template>
  <Head title="Admin — Users" />
  <AdminLayout>
    <header class="mb-4 flex items-end justify-between">
      <h1 class="font-display text-3xl text-ink">Users</h1>
      <input v-model="q" @keyup.enter="search" placeholder="Search email or name" class="rounded border border-hairline bg-bone-2 px-3 py-2 text-sm" />
    </header>

    <table class="w-full border-collapse text-sm">
      <thead class="text-left text-ink-3">
        <tr>
          <th class="py-2 pr-3">Email</th>
          <th class="py-2 pr-3">Name</th>
          <th class="py-2 pr-3">Role</th>
          <th class="py-2 pr-3">Resumes</th>
          <th class="py-2 pr-3">Apps</th>
          <th class="py-2 pr-3">Last login</th>
          <th class="py-2 pr-3">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-hairline">
        <tr v-for="user in users.data" :key="user.id">
          <td class="py-2 pr-3"><Link :href="route('admin.users.show', user.id)" class="text-ink hover:text-oxblood">{{ user.email }}</Link></td>
          <td class="py-2 pr-3 text-ink-2">{{ user.name }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.role }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.resumes_count }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.applications_count }}</td>
          <td class="py-2 pr-3 text-ink-3">{{ user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'never' }}</td>
          <td class="py-2 pr-3"><span v-if="user.deactivated_at" class="text-oxblood">Deactivated</span><span v-else class="text-ink-3">Active</span></td>
        </tr>
      </tbody>
    </table>
  </AdminLayout>
</template>
