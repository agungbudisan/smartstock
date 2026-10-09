<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // Entri terakhir yang gagal validasi, dikirim MovementController::entriLama().
    // Tanpa ini, redirect `back()` akan merender ulang komponen dengan state
    // default: mode kembali ke Outbound dan semua isian yang sudah diketik hilang.
    oldMovement: { type: Object, default: () => ({}) },
    products: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    // { [productId]: [{ id, name }] } — pemasok yang sudah pernah terait dengan
    // produk itu, digabung dari pemetaan pivot dan riwayat movements.
    suppliersByProduct: { type: Object, default: () => ({}) },
    movementTypes: { type: Array, required: true },
});

const page = usePage();

const lama = props.oldMovement ?? {};
const mode = ref(lama.movement_type ?? 'outbound');

/**
 * Default ke waktu lokal device, lalu diformat Y-m-d / H:i supaya bisa
 * langsung dipakai `<input type="date">` dan `<input type="time">`.
 *
 * Sengaja memakai waktu device, bukan `now()` dari server: defaultnya
 *"kapan sekarang menurut orang yang sedang mengisi form".
 */
const deviceSekarang = new Date();
const pad = (n) => String(n).padStart(2, '0');

const hariIni =
    `${deviceSekarang.getFullYear()}-${pad(deviceSekarang.getMonth() + 1)}-${pad(deviceSekarang.getDate())}`;

const jamIni = `${pad(deviceSekarang.getHours())}:${pad(deviceSekarang.getMinutes())}`;

const form = useForm({
    movement_type: mode.value,
    supplier_id: lama.supplier_id ?? '',
    reference_code: lama.reference_code ?? '',

    // Kalau dokumen lama tidak punya baris (mis. form baru), tetap sediakan
    // satu baris supaya operator tinggal mengisi, bukan menekan tombol dulu.
    items: lama.items?.length
        ? lama.items.map((baris) => ({ ...baris }))
        : [{ product_id: '', quantity: '', physical_count: null, unit_price: null }],

    // Dikirim sebagai `tanggal` + `waktu`, bukan `movement_date` langsung.
    // `StoreMovementRequest::prepareForValidation()` menggabungkannya
    // sebelum validasi, jadi controller tetap menerima satu field
    // `movement_date` berisi objek tanggal utuh.
    tanggal: lama.tanggal ?? hariIni,
    waktu: lama.waktu ?? jamIni,
});

/**
 * Nomor referensi juga mode-aware, supaya jelas kenapa isiannya dibersihkan
 * saat mode diganti dan nomor dokumen apa yang sebenarnya diharapkan.
 */
const referensiPerMode = {
    inbound: { label: 'Nomor PO / faktur pembelian', contoh: 'mis. PO-20261008-001' },
    outbound: { label: 'Nomor SO / nota pengiriman', contoh: 'mis. SO-20261008-001' },
    adjustment: { label: 'Nomor berita acara opname', contoh: 'mis. OPNAME-20261008-001' },
};

const referensi = computed(() => referensiPerMode[mode.value] ?? referensiPerMode.inbound);

/**
 * Opsi produk untuk satu baris, dalam bentuk yang bisa dicari.
 *
 * Produk yang sudah dipakai di baris LAIN dikeluarkan, jadi satu faktur tidak
 * bisa memuat SKU yang sama dua kali. Aturan itu sudah ada sebelum pencarian
 * dan sengaja tidak berubah: pemecahan otomatis diam-diam menghilangkan
 * informasi bahwa barang datang dalam dua terpisah.
 *
 * `hint`nya "stok ..." karena untuk Outbound angka itu menentukan apakah operator
 * entah boleh mengurangi sebanyak itu atau tidak.
 */
function opsiProduk(index) {
    return props.products
        .filter((p) => !form.items.some((b, i) => i !== index && String(b.product_id) === String(p.id)))
        .map((p) => ({
            id: p.id,
            label: `${p.sku} — ${p.name}`,
            hint: `stok ${p.current_stock}`,
        }));
}

function submit() {
    // PENTING untuk versi Inertia yang terpasang: `post(url, options)` hanya
    // menerima DUA argumen dan payload diambil otomatis dari objek form.
    // Tidak ada `form.toJSON()`, dan mengirim objek data sebagai argumen kedua
    // hanya akan menggeser `options` ke slot yang salah.
    //
    // Kolom yang tidak relevan untuk mode aktif sudah dikosongkan di setMode(),
    // jadi yang perlu dikirim hanyalah seluruh isi form apa adanya.
    form.post('/inventory/movements', { preserveScroll: true });
}

const typeLabels = {
    inbound: 'Inbound',
    outbound: 'Outbound',
    adjustment: 'Stock Opname',
};

const typeTone = {
    inbound: 'chip-success',
    outbound: 'chip-warning',
    adjustment: 'chip-primary',
};

function barisBaru() {
    return mode.value === 'adjustment'
        ? { product_id: '', quantity: null, physical_count: '', unit_price: null }
        : { product_id: '', quantity: '', physical_count: null, unit_price: null };
}

function tambahBaris() {
    form.items.push(barisBaru());
}

function hapusBaris(index) {
    // Faktur minimal harus punya satu baris. Mengosongkan seluruh form karena
    // pengguna menekan "hapus" adalah cara kehilangan pekerjaan tanpa sengaja.
    if (form.items.length === 1) {
        form.items[0] = barisBaru();
        return;
    }

    form.items.splice(index, 1);
}

function pesanBaris(index, kunci) {
    return form.errors[`items.${index}.${kunci}`] ?? null;
}

/**
 * Ganti mode dengan membersihkan kolom yang tidak berlaku untuk mode baru.
 *
 * Yang SENGAJA dipertahankan: baris item, tanggal, dan jam. Barang tidak
 * berubah hanya karena arah pergerakan dibalik, waktu transaksi juga tidak,
 * dan daftar baris yang sudah diketik masih relevan. Berpindah dari Inbound
 * 3 baris ke Outboard tidak boleh menghapus pekerjaan itu.
 *
 * Yang DIBERSIHKAN termasuk `reference_code`: nomor dokumen bersifat spesifik
 * per mode (PO untuk barang masuk, SO untuk barang keluar, berita acara untuk
 * opname). Membawa `PO-20261008-001` ke entri Outbound berarti jejak dokumen
 * yang palsu — dan `reference_code` ditulis apa adanya ke `inventory_movements`
 * dan ke description audit, jadi kesalahan di sini tidak bisa diperbaiki
 * belakangan. Mengetik ulang jauh lebih murah daripada dokumen yang salah.
 */
function setMode(next) {
    mode.value = next;
    form.movement_type = next;
    form.clearErrors();

    form.reference_code = '';

    if (next !== 'inbound') {
        form.supplier_id = '';
    }

    // Nilai kolom yang tidak berlaku mode baru dikosongkan per baris, tapi
    // barisnya sendiri tidak dihapus.
    form.items.forEach((baris, index) => {
        form.items[index] = next === 'adjustment'
            ? { ...baris, quantity: null, physical_count: baris.physical_count ?? '' }
            : { ...baris, quantity: baris.quantity ?? '', physical_count: null };

        if (next !== 'inbound') {
            form.items[index].unit_price = null;
        }
    });
}

function produkUntuk(baris) {
    if (!baris || !baris.product_id) return null;

    return props.products.find((p) => String(p.id) === String(baris.product_id)) ?? null;
}

/**
 * Stok setelah entri ini untuk satu baris.
 *
 * Dihitung per baris, bukan untuk seluruh dokumen: tiap produk punya stoknya
 * sendiri. Untuk opname, hasilnya adalah angka fisik yang diinput.
 */
function proyeksiBaris(baris) {
    const produk = produkUntuk(baris);

    if (!produk) return null;

    if (mode.value === 'inbound') return produk.current_stock + Number(baris.quantity || 0);
    if (mode.value === 'outbound') return produk.current_stock - Number(baris.quantity || 0);

    return Number(baris.physical_count || 0);
}

/**
 * Peringatan dini per baris.
 *
 * Diberi tahu operator sebelum submit, jangan hanya diam — termasuk saat stok
 * akan menjadi negatif, karena di situ sistem akan menolak dokumen SEUTUHNYA:
 * satu baris tidak cukup berarti semua baris batal.
 */
function peringatanBaris(baris) {
    const produk = produkUntuk(baris);
    const proyeksi = proyeksiBaris(baris);

    if (!produk || proyeksi === null) return null;

    if (proyeksi < 0) {
        return `Stok akan menjadi ${proyeksi}. Seluruh faktur ini akan ditolak karena stok tidak cukup.`;
    }

    if (mode.value !== 'adjustment' && proyeksi <= produk.reorder_point) {
        return `Stok akan menjadi ${proyeksi}, di bawah batas aman ${produk.reorder_point}.`;
    }

    return null;
}

/**
 * Pemasok dikelompokkan dua bagian: yang sudah pernah menyuplai produk dalam
 * faktur ini, lalu sisanya.
 *
 * Sumbernya adalah GABUNGAN semua produk di baris, bukan satu produk.	Faktur
 * bisa menutup banyak SKU, dan tidak ada satu "produk terpilih" anymore --
 *(filter hanya satu produk) benar-benar tidak ada yang benar lagi kalau
 * fakturnya berisi lima.
 *
 * Ini filter, bukan pager: kalau hanya menampilkan yang terdaftar, admin tidak
 * akan bisa mencatat barang masuk dari pemasok baru sebelum sempat
 * mendaftarkannya di halaman Kelola Pemasok. Memilih salah satu dari kelompok
 * kedua tetap aman karena pencatatan otomatis menautkannya.
 */
const supplierTerpilih = computed(() => {
    const ids = new Set(form.items.map((baris) => String(baris.product_id ?? '')).filter(Boolean));

    const hasil = new Map();

    ids.forEach((id) => {
        (props.suppliersByProduct[id] ?? []).forEach((s) => hasil.set(String(s.id), s));
    });

    return [...hasil.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const supplierLain = computed(() => {
    const sudah = new Set(supplierTerpilih.value.map((s) => String(s.id)));

    return props.suppliers.filter((s) => !sudah.has(String(s.id)));
});

const barisTerisi = computed(() => form.items.filter((baris) => String(baris.product_id ?? '') !== ''));

// Baris yang server tolak perlu ditandai supaya operator cepat menemukan
// culprit-nya di antara belasan baris.
const barisBermasalah = computed(() =>
    form.items
        .map((baris, index) => ({ index, pesan: pesanBaris(index, 'product_id') }))
        .filter((row) => row.pesan !== null),
);
</script>

<template>
    <Head title="Pergerakan Stok" />

    <AppLayout title="Operasional Gudang">
        <div class="mb-8">
            <p class="overline">Admin Gudang</p>
            <h1 class="mt-1 text-section">Entri Pergerakan Stok</h1>
            <p class="mt-1 text-body text-content-secondary">
                Setiap entri tercatat sebagai delta di buku besar dan otomatis masuk jejak audit.
            </p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="card h-fit p-6 lg:col-span-2">
                <!--
                Tumpuk di layar kecil. Tiga label mode ("Barang Masuk",
                "Barang Keluar", "Stock Opname") butuh sekitar 340px kalau
                dipaksa satu baris, sedangkan ruang di ponsel hanya sekitar
                270px setelah padding kartu. Memampitkannya memotong label --
                dan label yang terpotong persis di sakelar mode berarti
                operator tidak tahu mode apa yang sedang aktif.
            -->
            <div class="mb-5 grid gap-2 sm:grid-cols-3">
                <button
                    v-for="type in movementTypes"
                    :key="type.value"
                    type="button"
                    class="rounded-input px-3 py-2 text-small font-medium transition"
                    :class="
                        mode === type.value
                            ? 'bg-primary text-white'
                            : 'bg-surface-muted text-content-secondary hover:text-content'
                    "
                    @click="setMode(type.value)"
                >
                    {{ type.label }}
                </button>
            </div>

                <form class="space-y-4" @submit.prevent="submit">
                    <!--
                        BARIS-BARIS FAKTUR

                        Satu form = satu dokumen dengan banyak baris. Ini yang
                        membedakan tahap 3: sebelumnya satu produk per submit,
                        jadi purchase order 12 SKU harus disimpan dua belas kali
                        dan tiap kali jadi dokumen terpisah.

                        Setiap baris punya pratinjau stok sendiri karena tiap
                        produk punya stoknya sendiri -- tidak ada satu angka
                        gabungan yang berarti apa pun.
                    -->
                <div class="rounded-panel border border-hairline">
                    <div class="flex items-center justify-between border-b border-hairline px-4 py-3">
                        <div>
                            <p class="text-small font-medium">Baris faktur</p>
                            <p class="text-caption text-content-muted">
                                {{ form.items.length }} baris
                                <template v-if="barisTerisi.length">
                                    · {{ barisTerisi.length }} terisi
                                </template>
                            </p>
                        </div>
                        <button type="button" class="btn-secondary btn-sm" @click="tambahBaris">
                            + Tambah baris
                        </button>
                    </div>

                    <div v-for="(baris, index) in form.items" :key="index" class="border-b border-hairline px-4 py-4 last:border-b-0">
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-5">
                                <label class="label" :for="`item-${index}-product`">Produk</label>
                                <SearchableSelect
                                    :model-value="baris.product_id"
                                    :options="opsiProduk(index)"
                                    :invalid="!!pesanBaris(index, 'product_id')"
                                    required
                                    placeholder="Ketik SKU atau nama produk"
                                    empty-text="Produk tidak ditemukan. Periksa lagi SKU atau namanya."
                                    @update:model-value="baris.product_id = $event"
                                />
                                <p v-if="pesanBaris(index, 'product_id')" class="mt-1 text-caption text-danger">
                                    {{ pesanBaris(index, 'product_id') }}
                                </p>
                            </div>

<!--
                                Kolom jumlah.(mode !== 'adjustment') -- bukan sekadar
                                mengganti labelnya.

                                Untuk stock opname kolom ini TIDAK BOLEH ada: satu-satunya
                                isian yang bermakna adalah angka fisik hasil hitung.
                                Kalau "Jumlah keluar" ikut tampil di mode opname, dua
                                akibat buruk terjadi. Kosong -> kolomnya `required`, jadi
                                peramban memblokir submit dengan pesan pada field yang
                                tak berarti. Terisi -> nilainya diam-diam DIABAIKAN server,
                                karena untuk adjustment service memakai physical_count.

                                "Diam-diam diabaikan" lebih buruk dari error: operator
                                melihat angka yang ia ketik hilang tanpa penjelasan.
                            -->
                            <div v-if="mode !== 'adjustment'" :class="mode === 'inbound' ? 'sm:col-span-3' : 'sm:col-span-5'">
                                <label class="label" :for="`item-${index}-qty`">
                                    {{ mode === 'inbound' ? 'Jumlah barang masuk' : 'Jumlah barang keluar' }}
                                </label>
                                <input
                                    :id="`item-${index}-qty`"
                                    v-model.number="baris.quantity"
                                    type="number"
                                    min="1"
                                    class="input"
                                    required
                                />
                                <p v-if="pesanBaris(index, 'quantity')" class="mt-1 text-caption text-danger">
                                    {{ pesanBaris(index, 'quantity') }}
                                </p>
                            </div>

                            <!--
                                Angka fisik: satu-satunya isian yang berlaku untuk stock
                                opname, jadi ia memakai kolom utama baris.
                            -->
                            <div v-else class="sm:col-span-5">
                                <label class="label" :for="`item-${index}-fisik`">Jumlah fisik hasil hitung</label>
                                <input
                                    :id="`item-${index}-fisik`"
                                    v-model.number="baris.physical_count"
                                    type="number"
                                    min="0"
                                    class="input"
                                    required
                                />
                                <p class="mt-1 text-caption text-content-muted">
                                    Angka fisik, bukan selisih. Sistem menghitung selisihnya sendiri.
                                </p>
                                <p v-if="pesanBaris(index, 'physical_count')" class="mt-1 text-caption text-danger">
                                    {{ pesanBaris(index, 'physical_count') }}
                                </p>
                            </div>

                            <!--
                                Harga satuan hanya berlaku untuk barang masuk. Pada opname
                                tidak ada transaksi pembelian -- yang dicatat adalah selisih
                                antara fisik dan sistem, bukan nilai barangnya.
                            -->
                            <div v-if="mode === 'inbound'" class="sm:col-span-2">
                                <label class="label" :for="`item-${index}-harga`">
                                    Harga satuan
                                </label>
                                <input
                                    :id="`item-${index}-harga`"
                                    v-model.number="baris.unit_price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="input"
                                />
                                <p v-if="pesanBaris(index, 'unit_price')" class="mt-1 text-caption text-danger">
                                    {{ pesanBaris(index, 'unit_price') }}
                                </p>
                            </div>

                            <div class="flex items-end sm:col-span-2">
                                <button
                                    type="button"
                                    class="btn-ghost btn-sm w-full"
                                    :disabled="form.items.length === 1"
                                    :title="form.items.length === 1 ? 'Faktur minimal punya satu baris' : 'Hapus baris ini'"
                                    @click="hapusBaris(index)"
                                >
                                    Hapus
                                </button>
                            </div>
                        </div>


                        <div
                            v-if="peringatanBaris(baris)"
                            class="mt-3 rounded-input bg-amber-50 px-3 py-2 text-caption text-amber-700"
                        >
                            {{ peringatanBaris(baris) }}
                        </div>

                        <p
                            v-else-if="produkUntuk(baris) && proyeksiBaris(baris) !== null"
                            class="mt-3 text-caption text-content-muted"
                        >
                            Stok setelah entri ini:
                            <span class="font-display text-small text-content">
                                {{ proyeksiBaris(baris) }}
                            </span>
                        </p>
                    </div>
                </div>

                <p v-if="form.errors.items" class="text-caption text-danger">
                    {{ form.errors.items }}
                </p>

                <p v-if="barisBermasalah.length > 1" class="text-caption text-content-muted">
                    {{ barisBermasalah.length }} baris bermasalah. Perbaiki nomor barisnya yang ditandai.
                </p>

                    <div v-if="mode === 'inbound'">
                        <label class="label" for="supplier">Pemasok</label>
                        <select id="supplier" v-model="form.supplier_id" class="input">
                            <option value="">Tanpa pemasok</option>

                            <optgroup v-if="supplierTerpilih.length" :label="`Pernah menyuplai produk di faktur ini (${supplierTerpilih.length})`">
                                <option v-for="s in supplierTerpilih" :key="s.id" :value="s.id">
                                    {{ s.name }}
                                </option>
                            </optgroup>

                            <optgroup v-if="supplierLain.length" :label="`Belum pernah menyuplai produk di faktur ini (${supplierLain.length})`">
                                <option v-for="s in supplierLain" :key="`lain-${s.id}`" :value="s.id">
                                    {{ s.name }}
                                </option>
                            </optgroup>
                        </select>
                        <p class="mt-1 text-caption text-content-muted">
                            Satu faktur hanya bisa punya satu pemasok. Kalau barang datang dari dua
                            pemasok, itu dua faktur -- jadi simpan terpisah.
                        </p>
                        <p v-if="form.errors.supplier_id" class="mt-1 text-caption text-danger">
                            {{ form.errors.supplier_id }}
                        </p>
                    </div>

                    <div>
                        <label class="label" for="reference">{{ referensi.label }}</label>
                        <input
                            id="reference"
                            v-model="form.reference_code"
                            type="text"
                            maxlength="100"
                            :placeholder="referensi.contoh"
                            class="input"
                        />
                        <p class="mt-1 text-caption text-content-muted">
                            Berbeda tiap mode, jadi isiannya dibersihkan kalau mode diganti.
                        </p>
                        <p v-if="form.errors.reference_code" class="mt-1 text-caption text-danger">
                            {{ form.errors.reference_code }}
                        </p>
                    </div>

                    <!--
                        Waktu transaksi, terpisah dari waktu input.

                        Sebelum ada kolom ini, `movement_date` selalu jatuh ke
                        `now()`, jadi identik dengan `created_at`. Padahal di
                        gudang barang tiba jam 14:00 dan dicatat jam 16:00,
                        atau dokumen yesterday baru dimasukkan hari ini --
                        dua hal yang wajar dan tidak bisa direpresentasikan.

                        Nilai defaultnya "sekarang" supaya jalur umum tetap
                        sekali klik, tapi bisa diubah ke belakang untuk
                        mencatat barang yang telat didokumentasikan.
                    -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="tanggal">Tanggal transaksi</label>
                            <input
                                id="tanggal"
                                v-model="form.tanggal"
                                type="date"
                                :max="hariIni"
                                class="input"
                            />
                            <p v-if="form.errors.tanggal" class="mt-1 text-caption text-danger">
                                {{ form.errors.tanggal }}
                            </p>
                        </div>

                        <div>
                            <label class="label" for="waktu">Jam</label>
                            <input id="waktu" v-model="form.waktu" type="time" step="60" class="input" />
                            <p v-if="form.errors.waktu" class="mt-1 text-caption text-danger">
                                {{ form.errors.waktu }}
                            </p>
                        </div>
                    </div>
                    <p class="text-caption text-content-muted">
                        Boleh diisi tanggal lalu, untuk barang yang telat dicatat. Tidak boleh tanggal besok.
                    </p>

                    <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan...' : `Simpan ${typeLabels[mode]}` }}
                    </button>
                </form>
            </section>

            <!--
                Buku Besar tidak lagi ditampilkan di sini. Halaman `/inventory/ledger`
                sudah punya tabelnya sendiri yang bisa disaring, sedangkan tabel
                di bawah form dulu selalu menampilkan 20 baris terbaru tanpa filter
                yang bisa dipakai dari form. Dua salipan data yang sama di dua
                halaman juga selalu berbeda: begitu ada perbaikan di salah satunya,
                yang lain tertinggal tanpa ada yang menyadarinya.
                Yang tersisa di sini hanya tautannya: setelah menyimpan, operator
                sering ingin memastikan entrinya tercatat, dan mencari lewat sidebar
                menambah langkah yang tidak perlu.
            -->
            <aside class="hidden h-fit lg:col-span-1 lg:block">
                <div class="card p-5">
                    <p class="overline">Setelah menyimpan</p>
                    <h2 class="mt-1 text-subhead">Di mana entri ini tercatat?</h2>
                    <ul class="mt-3 space-y-2.5">
                        <li>
                            <Link
                                href="/inventory/ledger"
                                class="group flex items-start gap-2.5 text-small hover:text-primary"
                            >
                                <span>
                                    <span class="block font-medium">Buku Besar Stok</span>
                                    <span class="block text-caption text-content-muted">
                                        Setiap entri jadi satu delta di sini, dengan nomor faktur dan pencatatnya.
                                    </span>
                                </span>
                            </Link>
                        </li>
                        <li>
                            <Link
                                href="/inventory/audit-logs"
                                class="group flex items-start gap-2.5 text-small hover:text-primary"
                            >
                                <span>
                                    <span class="block font-medium">Jejak Audit</span>
                                    <span class="block text-caption text-content-muted">
                                        Riwayat siapa yang mengubah apa, lengkap dengan waktu pengetikannya.
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <div class="card mt-4 p-5">
                    <p class="overline">Aturan</p>
                    <ul class="mt-2 space-y-1.5 text-caption text-content-secondary">
                        <li>Satu faktur = satu jenis barang masuk.</li>
                        <li>Satu SKU tidak boleh muncul dua kali dalam satu faktur.</li>
                        <li>Barang dari dua pemasok berarti dua faktur terpisah.</li>
                    </ul>
                </div>
            </aside>

        </div>
    </AppLayout>
</template>
