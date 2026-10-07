<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

/**
 * TextInput sekarang membungkus <input> di dalam <div>, jadi instance
 * komponen tidak punya .focus() langsung. Harus turun ke elemen aslinya.
 */
function focusField(componentRef) {
    const el = componentRef?.$el ?? componentRef;

    if (typeof el?.focus === 'function') {
        el.focus();

        return;
    }

    el?.querySelector?.('input')?.focus();
}

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                focusField(passwordInput.value);
            }

            if (form.errors.current_password) {
                form.reset('current_password');
                focusField(currentPasswordInput.value);
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-subhead">Ubah Password</h2>
            <p class="mt-1 text-small text-content-secondary">
                Gunakan password panjang dan acak. Jangan memakai password yang sama dengan email Anda.
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-5">
            <div>
                <label class="label" for="current_password">Password saat ini</label>

                <TextInput
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    :error="form.errors.current_password"
                    autocomplete="current-password"
                />
            </div>

            <div>
                <label class="label" for="password">Password baru</label>

                <TextInput
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    :error="form.errors.password"
                    autocomplete="new-password"
                />
            </div>

            <div>
                <label class="label" for="password_confirmation">Ulangi password baru</label>

                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    :error="form.errors.password_confirmation"
                    autocomplete="new-password"
                />
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