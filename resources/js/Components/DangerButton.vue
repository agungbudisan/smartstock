<script setup>
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button :type="type" :disabled="disabled" class="btn-danger disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0">
        <slot />
    </button>
</template>