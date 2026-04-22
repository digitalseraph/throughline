<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps<{
  user: { id: number; name: string; email: string; role: string; last_login_at: string | null; deactivated_at: string | null; resume_count: number; application_count: number };
}>();

function toggleRole() {
  const nextRole = props.user.role === 'admin' ? 'user' : 'admin';
  if (window.confirm(`Change role to ${nextRole}?`)) {
    router.patch(route('admin.users.update', props.user.id), { role: nextRole });
  }
}

function deactivate() {
  if (window.confirm('Deactivate this user? They will no longer be able to log in.')) {
    router.patch(route('admin.users.update', props.user.id), { deactivate: true });
  }
}

function reactivate() {
  router.patch(route('admin.users.update', props.user.id), { reactivate: true });
}

function impersonate() {
  if (window.confirm(`Impersonate ${props.user.email}?`)) {
    router.post(route('admin.impersonate.start', props.user.id));
  }
}
</script>

<template>
  <Head :title="`Admin — ${user.email}`" />
  <AdminLayout>
    <Link :href="route('admin.users.index')" class="text-sm text-ink-3 hover:text-ink">&larr; All users</Link>
    <h1 class="mt-2 font-display text-3xl text-ink">{{ user.email }}</h1>
    <p class="text-ink-2">{{ user.name }} · {{ user.role }}</p>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Resumes</dt>
        <dd class="font-display text-xl text-ink">{{ user.resume_count }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Applications</dt>
        <dd class="font-display text-xl text-ink">{{ user.application_count }}</dd>
      </div>
      <div class="rounded border border-hairline bg-bone-2/40 px-4 py-3">
        <dt class="text-xs text-ink-3">Last login</dt>
        <dd class="font-display text-xl text-ink">{{ user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'never' }}</dd>
      </div>
    </dl>

    <div class="mt-8 flex flex-wrap gap-2">
      <button type="button" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="toggleRole">
        Set role to {{ user.role === 'admin' ? 'user' : 'admin' }}
      </button>
      <button v-if="user.role !== 'admin'" type="button" class="rounded border border-oxblood px-3 py-1 text-sm text-oxblood" @click="impersonate">
        Impersonate
      </button>
      <button v-if="!user.deactivated_at" type="button" class="rounded border border-ink-3 px-3 py-1 text-sm text-ink-3" @click="deactivate">
        Deactivate
      </button>
      <button v-else type="button" class="rounded border border-ink-3 px-3 py-1 text-sm" @click="reactivate">
        Reactivate
      </button>
    </div>
  </AdminLayout>
</template>
