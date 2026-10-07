<script setup>
defineProps({
    value: {
        type: String,
    },
    // `for` HTML, dipakai agar label benar-benar terhubung dengan kolomnya
    // (dan supaya screen reader membacakan label yang tepat).
    for: {
        type: String,
        default: undefined,
    },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <label :for="for" class="label">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>

        <span v-if="required" class="ml-0.5 text-danger" aria-hidden="true">*</span>
    </label>
</template>