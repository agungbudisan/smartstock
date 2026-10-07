<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: [String, Number],
    default: '',
});

const props = defineProps({
    // Alias dari atribut HTML native, diteruskan apa adanya ke <input>.
    type: { type: String, default: 'text' },
    name: { type: String, default: undefined },
    autocomplete: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    // Pesan error di bawah kolom; kolom diberi border merah kalau ada.
    error: { type: String, default: undefined },
});

defineOptions({ inheritAttrs: false });

const input = ref(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div>
        <input
            v-bind="$attrs"
            ref="input"
            v-model="model"
            :type="type"
            :name="name"
            :autocomplete="autocomplete"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            :aria-invalid="error ? 'true' : undefined"
            :class="['input', error ? 'input-error' : '']"
        />

        <p v-if="error" class="mt-1 text-caption text-danger">{{ error }}</p>
    </div>
</template>