<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const emit = defineEmits<{ uploaded: []; close: [] }>();

const name = ref('');
const file = ref<File | null>(null);
const uploading = ref(false);
const error = ref<string | null>(null);

function onFile(event: Event) {
  const target = event.target as HTMLInputElement;
  file.value = target.files?.[0] ?? null;
}

function submit() {
  error.value = null;

  if (!file.value || !name.value.trim()) {
    error.value = 'Name and file are required.';
    return;
  }

  const form = new FormData();
  form.append('name', name.value.trim());
  form.append('file', file.value);

  uploading.value = true;
  router.post(route('resumes.store'), form, {
    forceFormData: true,
    onSuccess: () => emit('uploaded'),
    onError: (errors) => {
      error.value = Object.values(errors).flat().join(' ');
      uploading.value = false;
    },
    onFinish: () => { uploading.value = false; },
  });
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4" @click.self="emit('close')">
    <div class="w-full max-w-md rounded-lg bg-bone p-6 shadow-lg">
      <h2 class="font-display text-2xl text-ink">Upload resume</h2>
      <p class="mt-1 text-sm text-ink-3">PDF or DOCX, up to 10 MB.</p>

      <form class="mt-5 space-y-4" @submit.prevent="submit">
        <label class="block">
          <span class="text-sm text-ink-2">Name (e.g. Staff frontend)</span>
          <input v-model="name" type="text" class="mt-1 w-full rounded border border-hairline bg-bone-2 px-3 py-2" />
        </label>
        <label class="block">
          <span class="text-sm text-ink-2">File</span>
          <input type="file" accept=".pdf,.docx" class="mt-1 w-full text-sm" @change="onFile" />
        </label>
        <p v-if="error" class="text-sm text-oxblood">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded px-4 py-2 text-sm text-ink-3" @click="emit('close')">Cancel</button>
          <button type="submit" class="rounded-full bg-oxblood px-5 py-2 text-sm font-medium text-bone disabled:opacity-50" :disabled="uploading">
            {{ uploading ? 'Uploading…' : 'Upload' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
