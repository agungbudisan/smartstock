<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout title="Password baru" subtitle="Pastikan password berbeda dari password sebelumnya.">
        <Head title="Atur Password Baru" />

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="email">Email</label>

                <TextInput
                    id="email"
                    type="email"
                    v-model="form.email"
                    :error="form.errors.email"
                    required
                    autocomplete="username"
                />
            </div>

            <div>
                <label class="label" for="password">Password baru</label>

                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    :error="form.errors.password"
                    required
                    autofocus
                    autocomplete="new-password"
                />
            </div>

            <div>
                <label class="label" for="password_confirmation">Ulangi password baru</label>

                <TextInput
                    id="password_confirmation"
                    type="password"
                    v-model="form.password_confirmation"
                    :error="form.errors.password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </div>

            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Menyimpan...">
                Simpan password baru
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>