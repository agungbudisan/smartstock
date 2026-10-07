<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

/** TextInput membungkus <input> di dalam <div>; fokus harus ke elemen aslinya. */
function focusPassword() {
    const el = passwordInput.value?.$el ?? passwordInput.value;

    (typeof el?.focus === 'function' ? el : el?.querySelector?.('input'))?.focus();
}

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => focusPassword());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => focusPassword(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-subhead">Hapus Akun</h2>
            <p class="mt-1 text-small text-content-secondary">
                Akun yang dihapus akan dihapus permanen beserta seluruh datanya. Unduh dulu
                laporan yang masih Anda perlukan.
            </p>
        </header>

        <div class="mt-5">
            <DangerButton type="button" @click="confirmUserDeletion">Hapus Akun</DangerButton>
        </div>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-subhead">Yakin ingin menghapus akun?</h2>

                <p class="mt-1 text-small text-content-secondary">
                    Tindakan ini tidak dapat dibatalkan. Masukkan password Anda untuk konfirmasi.
                </p>

                <div class="mt-6">
                    <label class="label" for="delete-password">Password</label>

                    <TextInput
                        id="delete-password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        :error="form.errors.password"
                        autocomplete="current-password"
                        placeholder="Password"
                        @keyup.enter="deleteUser"
                    />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton @click="closeModal">Batal</SecondaryButton>

                    <DangerButton :loading="form.processing" loading-text="Menghapus..." @click="deleteUser">
                        Hapus Akun
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>