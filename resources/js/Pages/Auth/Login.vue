<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout title="Masuk ke SmartStock" subtitle="Gunakan akun yang diberikan administrator gudang.">
        <Head title="Masuk" />

        <div v-if="status" class="rounded-panel bg-emerald-50 px-4 py-3 text-small text-emerald-800">
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

            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <label class="label" for="password">Password</label>

                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-caption text-primary hover:underline"
                    >
                        Lupa password?
                    </Link>
                </div>

                <TextInput
                    id="password"
                    type="password"
                    v-model="form.password"
                    :error="form.errors.password"
                    required
                    autocomplete="current-password"
                />
            </div>

            <label class="flex items-center gap-2">
                <Checkbox name="remember" v-model:checked="form.remember" />
                <span class="text-small text-content-secondary">Ingat saya di perangkat ini</span>
            </label>

            <PrimaryButton class="w-full" :loading="form.processing" loading-text="Memproses...">
                Masuk
            </PrimaryButton>
        </form>

        <template #footer>
            Belum punya akun?
            <Link :href="route('register')" class="font-medium text-primary hover:underline">Daftar</Link>
        </template>
    </GuestLayout>
</template>
