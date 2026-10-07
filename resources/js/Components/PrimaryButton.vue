<script setup>
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
    // Ganti label tombol selagi request berjalan, mis. "Menyimpan...".
    loading: { type: Boolean, default: false },
    loadingText: { type: String, default: 'Memproses...' },
});
</script>

<template>
    <button :type="type" :disabled="disabled || loading" class="btn-primary disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0">
        <span v-if="loading">{{ loadingText }}</span>
        <slot v-else />
    </button>
</template>