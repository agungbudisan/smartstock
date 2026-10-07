<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout title="Daftar akun baru" subtitle="Peran akan ditentukan administrator setelah pendaftaran.">
        <Head title="Daftar" />

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="name">Nama lengkap</label>

                <TextInput
                    id="name"
                    type="text"
                    v-model="form.name"
                    :error="form.errors.name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Budi Santoso"
                />
            </div>

            <div>
                <label class="label" for="email">Email</label>

                <TextInput
                    id="email"
                    type="email"
                    v-model="form.email"
                    :error="form.errors.email"
                    required
                    autocomplete="username"
                    placeholder="nama@gudang.com"
                />
            </div>

            <div>
                <label class="label" for="password">Password</label>

                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    :error="form.errors.password"
                    required
                    autocomplete="new-password"
                />
            </div>

            <div>
                <label class="label" for="password_confirmation">Ulangi password</label>

                <TextInput
                    id="password_confirmation"
                    type="password"
                    v-model="form.password_confirmation"
                    :error="form.errors.password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </div>

            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Mendaftarkan...">
                Daftar
            </PrimaryButton>
        </form>

        <template #footer>
            Sudah punya akun?
            <Link :href="route('login')" class="font-medium text-primary hover:underline">Masuk</Link>
        </template>
    </GuestLayout>
</template>