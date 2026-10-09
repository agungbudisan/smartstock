<script setup>
import ProductForm from '@/Pages/Products/Form.vue';

defineProps({
    categories: { type: Array, required: true },
    suppliers: { type: Array, default: () => [] },
    // Saran SKU per kategori, sudah termasuk `sku_prefix`.
    saranSku: { type: Object, default: () => ({}) },
    // Hanya Admin Gudang yang boleh mengisi kolom stok awal.
    bolehCatatStok: { type: Boolean, default: false },
});
</script>

<template>
    <ProductForm
        :categories="categories"
        :suppliers="suppliers"
        :saran-sku="saranSku"
        :boleh-catat-stok="bolehCatatStok"
        submit-url="/products"
        method="post"
    />
</template>