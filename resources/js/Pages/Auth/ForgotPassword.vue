<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout title="Lupa password" subtitle="Kami akan mengirim tautan pengaturan ulang ke email Anda.">
        <Head title="Lupa Password" />

        <div v-if="status" class="mb-5 rounded-panel bg-emerald-50 px-4 py-3 text-small text-emerald-800">
            {{ status }}
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="email">Email</label>

                <TextInput
                    id="email"
                    type="email"
                    v-model="form.email"
                    :error="form.errors.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="nama@gudang.com"
                />
            </div>

            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Mengirim...">
                Kirim tautan reset
            </PrimaryButton>
        </form>

        <template #footer>
            <Link :href="route('login')" class="font-medium text-primary hover:underline">Kembali ke halaman masuk</Link>
        </template>
    </GuestLayout>
</template>