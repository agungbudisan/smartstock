<?php

namespace App\Http\Controllers;

use App\Notifications\LowStockNotification;
use App\Services\LowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Pengelolaan notifikasi (channel `database`).
 *
 * Rute ini hanya untuk menandai notifikasi sudah dibaca — tidak ada halaman
 * daftar penuh, karena notifikasi yang relevan sudah tampil sebagai kartu di
 * Dasbor & halaman Reorder.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly LowStockAlertService $alerts) {}

    /**
     * Tandai semua notifikasi sebagai sudah dibaca.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $count = $request->user()
            ->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with(
            'success',
            $count > 0
                ? $count.' notifikasi ditandai sudah dibaca.'
                : 'Tidak ada notifikasi baru.',
        );
    }

    /**
     * Tandai satu notifikasi sebagai sudah dibaca, lalu kembali ke halaman asal.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        /** @var DatabaseNotification|null $item */
        $item = $request->user()->notifications()->find($notification);

        if ($item === null) {
            // Notifikasi milik orang lain tidak boleh bisa dibuka lewat URL.
            return back()->with('warning', 'Notifikasi tidak ditemukan.');
        }

        if ($item->type !== LowStockNotification::class) {
            return back()->with('warning', 'Notifikasi ini tidak bisa ditandai.');
        }

        $item->markAsRead();

        $target = is_array($item->data) && isset($item->data['action_url'])
            ? $item->data['action_url']
            : null;

        if ($target !== null) {
            // `action_url` berasal dari server sendiri (product_id integer),
            // jadi aman dipakai sebagai redirect dalam aplikasi.
            return redirect($target);
        }

        return back()->with('success', 'Notifikasi ditandai sudah dibaca.');
    }

    /**
     * Hapus notifikasi yang sudah dibaca (pemeliharaan manual dari UI).
     */
    public function clearRead(Request $request): RedirectResponse
    {
        $count = $request->user()
            ->notifications()
            ->whereNotNull('read_at')
            ->delete();

        return back()->with(
            'success',
            $count > 0
                ? $count.' notifikasi lama dihapus.'
                : 'Tidak ada notifikasi yang sudah dibaca.',
        );
    }
}
