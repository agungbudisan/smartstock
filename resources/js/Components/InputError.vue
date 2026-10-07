<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <p v-if="message" class="mt-1 text-caption text-danger" role="alert">
        {{ message }}
    </p>
</template>