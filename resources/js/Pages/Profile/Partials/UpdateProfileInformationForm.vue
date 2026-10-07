<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-subhead">Informasi Akun</h2>
            <p class="mt-1 text-small text-content-secondary">
                Nama dan email yang dipakai untuk masuk ke aplikasi.
            </p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-5">
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
                />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="rounded-panel bg-amber-50 px-4 py-3 text-small text-amber-800">
                    Email Anda belum diverifikasi.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="font-medium underline"
                    >
                        Kirim ulang email verifikasi.
                    </Link>
                </p>

                <p
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 rounded-panel bg-emerald-50 px-4 py-3 text-small text-emerald-800"
                >
                    Tautan verifikasi baru sudah dikirim ke email Anda.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :loading="form.processing" loading-text="Menyimpan...">
                    Simpan
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-small text-content-secondary">
                        Tersimpan.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>