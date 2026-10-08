<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    /**
     * Dokumen pergerakan yang dicatat oleh user ini.
     *
     * Sejak tahap 4 `user_id` tidak lagi ada di baris detail — pencatat milik
     * dokumen. Relasi lama (`hasMany` ke kolom `user_id`) tidak bisa
     * dipertahankan dan tidak dibiarkan menggantung: dipanggil, ia melempar
     * "no such column" saat query.
     *
     * @return HasMany<InventoryTransaction, $this>
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * Semua baris detail dari dokumen-dokumen di atas.
     *
     * `hasManyThrough` dua hop, jadi jangan dipakai di halaman yang hanya butuh
     * dokumen. Ada supaya pertanyaan "apa saja yang dicatat orang ini" tetap
     * bisa dijawab tanpa menulis ulang kuerinya.
     *
     * @return HasManyThrough<InventoryMovement, InventoryTransaction, $this>
     */
    public function inventoryMovements(): HasManyThrough
    {
        return $this->hasManyThrough(
            InventoryMovement::class,
            InventoryTransaction::class,
            'user_id',
            'transaction_id',
            'id',
            'id',
        );
    }

    /**
     * Jejak rekam aktivitas milik user ini.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Notifikasi milik user ini (channel `database`).
     *
     * WAJIB `morphMany`, bukan `hasMany`: kolomnya `notifiable_id` +
     * `notifiable_type`, sama seperti yang didefinisikan trait `Notifiable`.
     * Mengganti dengan `hasMany` membuat Laravel mengira foreign key-nya
     * `user_id` dan seluruh query notifikasi gagal dengan
     * "no such column: notifications.user_id".
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    // -----------------------------------------------------------------
    // Otorisasi dasar (akan dipakai middleware RBAC di Fase 6)
    // -----------------------------------------------------------------

    /**
     * Cek apakah user punya salah satu dari peran yang diberikan.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Admin Gudang: entitled melakukan entri inbound/outbound/opname.
     */
    public function isAdminGudang(): bool
    {
        return $this->hasRole(UserRole::AdminGudang);
    }

    /**
     * Manager Bisnis: entitled melihat dasbor analitik & prediksi.
     */
    public function isManagerBisnis(): bool
    {
        return $this->hasRole(UserRole::ManagerBisnis);
    }

    /**
     * Notifikasi ikut terhapus bersama akunnya. Tabel `notifications` tidak
     * memakai foreign key (kolomnya generik `notifiable_type`), jadi pembersihan
     * dilakukan di sini.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            $user->notifications()->delete();
        });
    }
}
