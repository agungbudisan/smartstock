<?php

namespace App\Models;

use App\Enums\MovementType;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HEADER dokumen pergerakan stok — header-detail pemecahan tahap 1 dari 5.
 *
 * Satu baris = satu dokumen (faktur, nota, berita acara). Baris-baris isi
 * dokumennya ada di `inventory_movements` sebagai detail.
 *
 * MENGAPA DUA KOLOM KODE:
 *
 *  - `system_code` (INB-20261008-0001) adalah identitas internal yang selalu
 *    ada dan dijamin unik. Tidak ada orang yang perlu membacanya dari dokumen
 *    fisik, jadi mengacaknya tidak memalsukan apa pun.
 *
 *  - `physical_reference` (PO-20261008-001) adalah nomor yang tercetak milik
 *    pihak lain. Nullable, karena entri internal sering tidak punya dokumen
 *    eksternal sama sekali: stok pembuka hasil hitung fisik, koreksi yang
 *    berdiri sendiri. Kalau kolom ini diisi, isinya SALAH berarti jejak audit
 *    menunjuk dokumen yang tidak ada.
 *
 *Aturan yang menempel di sini, bukan per detail: pemasok. Satu PO tidak
 * mungkin punya dua pemasok — kalau iya, itu dua dokumen berbeda dan harus
 * dipisah saat pencatatan.
 */
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'system_code',
        'physical_reference',
        'movement_type',
        'supplier_id',
        'user_id',
        'transaction_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'transaction_date' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    /**
     * Baris detail dokumen ini.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'transaction_id');
    }

    /**
     * Pemasok menempel di dokumen, bukan di tiap baris.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Siapa yang mencatat dokumen ini.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * @param  Builder<InventoryTransaction>  $query
     */
    public function scopeOfType(Builder $query, MovementType $type): void
    {
        $query->where('movement_type', $type->value);
    }

    // -----------------------------------------------------------------
    // Bantu
    // -----------------------------------------------------------------

    /**
     * Prefix `system_code` per jenis pergerakan.
     *
     * Dipisah dari kelas enum supaya model ini tidak perlu tahu soal
     * penomoran dokumen.
     */
    public static function prefixFor(MovementType $type): string
    {
        return match ($type) {
            MovementType::Inbound => 'INB',
            MovementType::Outbound => 'OUT',
            MovementType::Adjustment => 'ADJ',
        };
    }

    /**
     * Nomor sistem berikutnya untuk satu hari, mis. `INB-20261008-0007`.
     *
     * Berisi cara PENGHITUNGAN, bukan pembuatan. Pemanggil tetap wajib
     * `$transaction->save()` dan harus menangani tabrakan di tingkat database —
     * `system_code` punya unique index, dan dua operator yang menyimpan faktur
     * bersamaan akan berebut urutan yang sama.
     */
    public static function nextSystemCode(MovementType $type, string $date): string
    {
        $prefix = self::prefixFor($type);
        $hari = date('Ymd', strtotime($date));
        $slot = $prefix.'-'.$hari;

        $terakhir = static::query()
            ->where('system_code', 'like', $slot.'-%')
            ->orderByDesc('system_code')
            ->value('system_code');

        $urut = $terakhir === null
            ? 0
            : (int) substr((string) $terakhir, -4);

        return sprintf('%s-%s-%04d', $prefix, $hari, $urut + 1);
    }

    /**
     * Label yang enak dibaca manusia untuk ditampilkan di antarmuka.
     *
     * Memakai nomor fisik kalau ada — itulah yang dicari orang saat memegang
     * dokumen. `system_code` dipakai sebagai cadangan supaya baris tanpa
     * dokumen tetap punya identitas yang bisa ditampilkan.
     */
    public function displayReference(): string
    {
        return $this->physical_reference ?? $this->system_code;
    }
}
