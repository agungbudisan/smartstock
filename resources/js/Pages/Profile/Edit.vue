<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const page = usePage();
const roleLabel = page.props.auth.user?.role === 'manager_bisnis' ? 'Manager Bisnis' : 'Admin Gudang';
</script>

<template>
    <Head title="Profil" />

    <AppLayout title="Profil">
        <div class="mb-8">
            <p class="overline">Akun</p>
            <h1 class="mt-1 text-section">Profil Saya</h1>
            <p class="mt-1 text-body text-content-secondary">
                Peran Anda saat ini <span class="font-medium text-content">{{ roleLabel }}</span>.
                Peran hanya bisa diubah administrator.
            </p>
        </div>

        <div class="grid max-w-3xl gap-6">
            <section class="card p-6">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            </section>

            <section class="card p-6">
                <UpdatePasswordForm />
            </section>

            <section class="card border-danger/30 p-6">
                <DeleteUserForm />
            </section>
        </div>
    </AppLayout>
</template>