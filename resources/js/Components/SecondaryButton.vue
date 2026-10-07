<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button :type="type" :disabled="disabled" class="btn-secondary disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0">
        <slot />
    </button>
</template>