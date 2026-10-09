<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /** Jumlah notifikasi terbaru yang ikut dikirim ke lonceng. */
    private const NOTIFICATION_PREVIEW_LIMIT = 8;

    /**
     * Menu sidebar untuk peran pengguna.
     *
     * Sumbernya `config/navigation.php`, bukan komponen Vue, supaya daftar
     * menu bisa diuji sebagai PHP biasa -- lihat `NavigationReachableTest`.
     *
     * @return array<int, array{heading: string|null, items: array<int, array<string, string>>}>
     */
    private function navigation(User $user): array
    {
        $semuaGrup = config('navigation.groups');
        $urutan = config('navigation.per_role.'.$user->role->value, []);

        return array_values(array_filter(array_map(function (string $key) use ($semuaGrup, $user): ?array {
            $grup = $semuaGrup[$key] ?? null;

            if ($grup === null) {
                return null;
            }

            // Item boleh dibatasi ke sebagian peran. Tanpa ini, satu halaman
            // Admin-only di dalam grup bersama akan muncul juga di sidebar
            // Manager -- dan karena route-nya menolak, itu menu yang selalu gagal.
            $items = array_values(array_filter(
                $grup['items'] ?? [],
                fn (array $item): bool => ($item['roles'] ?? null) === null
                    || in_array($user->role->value, $item['roles'], true),
            ));

            return ['heading' => $grup['heading'] ?? null, 'items' => $items];
        }, $urutan)));
    }

    /**
     * Ringkasan notifikasi untuk lonceng di AppLayout.
     *
     * Hanya 8 terbaru yang dikirim — memuat seluruh riwayat notifikasi di
     * setiap request akan membuat payload halaman membengkak seiring waktu.
     *
     * @return array{unread_count: int, items: array<int, array<string, mixed>>}
     */
    private function notifications(User $user): array
    {
        $unreadCount = $user->notifications()
            ->whereNull('read_at')
            ->count();

        $items = $user->notifications()
            ->limit(self::NOTIFICATION_PREVIEW_LIMIT)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'read' => $notification->read_at !== null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ] + (is_array($notification->data) ? $notification->data : []))
            ->all();

        return [
            'unread_count' => $unreadCount,
            'items' => $items,
        ];
    }

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],

            // Flash dipakai FlashMessages.vue untuk pesan sukses/peringatan.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
            ],

            // Menu sidebar. Dibagikan di sini supaya `AppLayout.vue` tidak
            // pernah perlu tahu apa saja yang boleh dilihat peran mana.
            'navigation' => fn () => $request->user()
                ? $this->navigation($request->user())
                : [],

            // Lonceng notifikasi: dibagikan ke semua halaman supaya tidak
            // setiap controller harus mengirim prop-nya sendiri.
            'notifications' => fn () => $request->user()
                ? $this->notifications($request->user())
                : ['unread_count' => 0, 'items' => []],

            // 'errors' SENGAJA tidak didefinisikan ulang di sini. Induk class
            // sudah menyediakan resolveValidationErrors() yang mengubahnya jadi objek
            // {field: pesan pertama} — bentuk yang dibaca useForm() di Vue.
            // Memasang $request->errors (ViewErrorBag) di sini membuat prop
            // selalu null sehingga form.errors kosong dan pesan validasi tidak
            // pernah tampil di layar.
        ];
    }
}
