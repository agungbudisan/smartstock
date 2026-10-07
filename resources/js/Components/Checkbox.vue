<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
    disabled: { type: Boolean, default: false },
});

const proxyChecked = computed({
    get() {
        return props.checked;
    },

    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <input
        type="checkbox"
        :value="value"
        :disabled="disabled"
        v-model="proxyChecked"
        class="h-4 w-4 shrink-0 rounded border-hairline text-primary focus:ring-primary disabled:opacity-50"
    />
</template>