<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout
        title="Verifikasi email"
        subtitle="Klik tautan yang kami kirim ke email Anda sebelum mulai memakai aplikasi."
    >
        <Head title="Verifikasi Email" />

        <div
            v-if="verificationLinkSent"
            class="mb-5 rounded-panel bg-emerald-50 px-4 py-3 text-small text-emerald-800"
        >
            Tautan verifikasi baru sudah dikirim ke alamat email Anda.
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Mengirim...">
                Kirim ulang email verifikasi
            </PrimaryButton>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="block w-full text-center text-small text-content-secondary hover:text-content"
            >
                Keluar
            </Link>
        </form>
    </GuestLayout>
</template>