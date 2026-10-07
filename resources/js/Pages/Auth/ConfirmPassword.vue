<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout
        title="Konfirmasi password"
        subtitle="Ini area sensitif. Konfirmasi password Anda sebelum melanjutkan."
    >
        <Head title="Konfirmasi Password" />

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="password">Password</label>

                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    :error="form.errors.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
            </div>

            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Memproses...">
                Konfirmasi
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>