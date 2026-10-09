<script setup>
import BrandLogo from '@/Components/BrandLogo.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="flex min-h-screen flex-col justify-center bg-surface-muted px-6 py-12">
        <div class="mx-auto w-full max-w-md">
            <Link href="/" class="flex justify-center">
                <BrandLogo class="h-12 w-auto" />
            </Link>

            <p class="mt-1 text-center text-caption text-content-muted">
                Mini ERP inventaris gudang & prediksi permintaan
            </p>

            <div class="card mt-8 p-6 sm:p-8">
                <div v-if="title || $slots.header">
                    <slot name="header">
                        <h1 class="font-display text-subhead">{{ title }}</h1>
                        <p v-if="subtitle" class="mt-1 text-small text-content-secondary">{{ subtitle }}</p>
                    </slot>
                </div>

                <div :class="title || $slots.header ? 'mt-6' : ''">
                    <slot />
                </div>
            </div>

            <p v-if="$slots.footer" class="mt-6 text-center text-small text-content-secondary">
                <slot name="footer" />
            </p>
        </div>
    </div>
</template>