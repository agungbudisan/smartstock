<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const messages = computed(() => {
    const flash = page.props.flash ?? {};
    const items = [];

    if (flash.success) {
        items.push({ type: 'success', text: flash.success });
    }
    if (flash.warning) {
        items.push({ type: 'warning', text: flash.warning });
    }
    if (flash.error) {
        items.push({ type: 'danger', text: flash.error });
    }

    return items;
});

const styles = {
    success: 'chip-success',
    warning: 'chip-warning',
    danger: 'chip-danger',
};
</script>

<template>
    <div v-if="messages.length" class="mb-6 space-y-2">
        <div
            v-for="(item, index) in messages"
            :key="index"
            class="rounded-panel border border-hairline bg-surface px-4 py-3 text-small"
            :class="styles[item.type]"
            role="status"
        >
            {{ item.text }}
        </div>
    </div>
</template>