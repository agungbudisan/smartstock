<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi form operasional Admin Gudang (Inbound / Outbound / Stock Opname).
 *
 * Satu request melayani tiga mode; aturan berubah sesuai mode yang dipilih.
 */
class StoreMovementRequest extends FormRequest
{
    /**
     * Gabungkan `tanggal` + `waktu` menjadi satu `movement_date` sebelum
     * divalidasi.
     *
     * Form mengirim dua input terpisah karena `<input type="datetime-local">`
     * tidak konsisten antar peramban dan tampilannya buruk di sebagian
     * perangkat. Setelah digabung, seluruh request ini tetap hanya dealing
     * dengan satu field `movement_date` — sama seperti sebelum kolom ini ada.
     *
     * Kalau salah satu tidak terisi, keduanya dibiarkan kosong: `null` berarti
     * "pakai waktu sekarang", sama seperti form yang tidak mengisi apa pun.
     */
    protected function prepareForValidation(): void
    {
        $tanggal = $this->input('tanggal');
        $waktu = $this->input('waktu');

        if (is_string($tanggal) && $tanggal !== '' && is_string($waktu) && $waktu !== '') {
            $this->merge(['movement_date' => $tanggal.' '.$waktu.':00']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'movement_type' => ['required', Rule::in(MovementType::values())],

            // Satu request = satu faktur dengan banyak baris.

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],

            // Outbound & Inbound: jumlah barang (harus > 0).
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],

            // Stock Opname: jumlah fisik hasil hitung (boleh 0).
            'items.*.physical_count' => ['nullable', 'integer', 'min:0'],

            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],

            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'reference_code' => ['nullable', 'string', 'max:100'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'movement_date' => ['nullable', 'date'],

            // Dipasangkan oleh prepareForValidation() menjadi `movement_date`.
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'waktu' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTanggalPasangan($validator);
            $this->validateBaris($validator);

            $type = $this->movementType();

            match ($type) {
                MovementType::Adjustment => null,
                MovementType::Inbound => null,
                MovementType::Outbound => $this->validateStokCukup($validator),
                default => null,
            };
        });
    }

    /**
     * Validasi yang melingkupi seluruh baris, bukan per baris.
     *
     * Dua hal hanya bisa dicek setelah semua baris terlihat:
     *
     *  1. Produk yang sama tidak boleh muncul dua kali. Kalau iya, ledger akan
     *     punya dua baris untuk SKU yang sama dalam satu faktur — dan saat
     *     produknya diarsipkan, jejaknya jadi jauh lebih sulit dibaca.
     *     Ditolak, bukan digabung diam-diam, karena "10 + 5" dan "15" bisa
     *     berarti hal berbeda tergantung apakah itu dua penerimaan terpisah.
     *
     *  2. Stok cukup untuk SETIAP baris, bukan cuma baris pertama.
     */
    private function validateBaris(Validator $validator): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $terlihat = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? null;

            if ($productId === null || $productId === '') {
                continue;
            }

            $productId = (int) $productId;

            if (isset($terlihat[$productId])) {
                $validator->errors()->add(
                    "items.{$index}.product_id",
                    'Produk ini sudah ada di baris lain. Gabungkan jumlahnya jadi satu baris.',
                );
            }

            $terlihat[$productId] = true;

            $tipe = $this->movementType();

            if ($tipe === MovementType::Adjustment) {
                if (! isset($item['physical_count']) || $item['physical_count'] === '') {
                    $validator->errors()->add(
                        "items.{$index}.physical_count",
                        'Jumlah fisik hasil hitung wajib diisi untuk stock opname.',
                    );
                }
            } elseif (! isset($item['quantity']) || $item['quantity'] === '') {
                $validator->errors()->add(
                    "items.{$index}.quantity",
                    $tipe === MovementType::Inbound
                        ? 'Jumlah barang masuk wajib diisi.'
                        : 'Jumlah barang keluar wajib diisi.',
                );
            }
        }
    }

    /**
     * Stok cukup untuk setiap baris pada dokumen keluar.
     *
     * Dicek di sini (bukan hanya di service) supaya operator mendapat pesan
     * yang menunjuk baris mana yang bermasalah, sebelum transaksi dicoba.
     * Service tetap memeriksa ulang — lapisan validasi bukan jaminan.
     */
    private function validateStokCukup(Validator $validator): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $jumlah = (int) ($item['quantity'] ?? 0);

            if ($jumlah <= 0) {
                continue;
            }

            $produk = is_numeric($item['product_id'] ?? null)
                ? Product::find((int) $item['product_id'])
                : null;

            if ($produk !== null && $jumlah > $produk->current_stock) {
                $validator->errors()->add(
                    "items.{$index}.quantity",
                    "Stok {$produk->sku} tidak cukup. Tersedia {$produk->current_stock}, diminta {$jumlah}.",
                );
            }
        }
    }

    /**
     * Tanggal dan jam harus diisi berdua atau tidak diisi sama sekali.
     *
     * Salah satu saja terisi tidak bisa ditebak artinya: tanggal tanpa jam
     * berarti tengah malam, jam tanpa tanggal berarti hari ini. Daripada
     * mengarang, minta pengguna melengkapi pasangannya.
     */
    private function validateTanggalPasangan(Validator $validator): void
    {
        $tanggal = $this->input('tanggal');
        $waktu = $this->input('waktu');

        $adaTanggal = is_string($tanggal) && $tanggal !== '';
        $adaWaktu = is_string($waktu) && $waktu !== '';

        if ($adaTanggal && ! $adaWaktu) {
            $validator->errors()->add('waktu', 'Isi jamnya juga, atau kosongkan keduanya.');

            return;
        }

        if ($adaWaktu && ! $adaTanggal) {
            $validator->errors()->add('tanggal', 'Isi tanggalnya juga, atau kosongkan keduanya.');

            return;
        }

        // Penjaga terakhir: transaksi tidak boleh di masa depan.
        //
        // Error-nya sengaja dipasang ke `tanggal`, bukan ke `movement_date`.
        // Field `movement_date` tidak lagi punya kolom di form, jadi pesan
        // yang menempel di sana akan hilang tanpa pernah terlihat pengguna --
        // persis kelas bug "form gagal senyap" yang sudah pernah terjadi di
        // halaman produk.
        $effective = $this->input('movement_date');

        if (! is_string($effective) || $effective === '') {
            return;
        }

        try {
            if (Carbon::parse($effective)->isAfter(now())) {
                $validator->errors()->add('tanggal', 'Tanggal transaksi tidak boleh di masa depan.');
            }
        } catch (InvalidFormatException) {
            // Ditangani aturan `date` / `date_format` di atas.
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'movement_type.in' => 'Jenis pergerakan tidak dikenal.',
            'product_id.exists' => 'Produk yang dipilih tidak ada.',
            'quantity.min' => 'Jumlah harus lebih besar dari nol.',
            'physical_count.min' => 'Jumlah fisik tidak boleh negatif.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'produk',
            'quantity' => 'jumlah',
            'physical_count' => 'jumlah fisik',
            'supplier_id' => 'pemasok',
            'reference_code' => 'nomor referensi',
            'unit_price' => 'harga satuan',
        ];
    }

    // -----------------------------------------------------------------

    private function movementType(): ?MovementType
    {
        $raw = $this->input('movement_type');

        return is_string($raw) ? MovementType::tryFrom($raw) : null;
    }
}
