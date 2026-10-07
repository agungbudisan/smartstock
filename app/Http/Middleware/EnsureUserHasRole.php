<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga peran (PRD bagian 2: Role-Based Access Control).
 *
 * Dipakai sebagai `->middleware('role:admin_gudang,manager_bisnis')`.
 *
 * Admin Gudang   -> entri operasional stok (Inbound / Outbound / Stock Opname)
 * Manager Bisnis -> dasbor analitik, prediksi, pemantauan reorder point
 */
final class EnsureUserHasRole
{
    /**
     * @param  string  ...$roles  nilai UserRole, dipisah tanda koma pada route
     *
     * @throws InvalidArgumentException bila nama peran tidak dikenal
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        // Buang entri kosong supaya `role:` tanpa daftar tidak melempar error
        // (laravel mengoperasionalkan string kosong sebagai satu argumen).
        $requested = array_values(array_filter($roles, fn (string $role): bool => $role !== ''));

        $allowed = array_map(
            fn (string $role) => UserRole::tryFrom($role) ?? throw new InvalidArgumentException(
                "Peran '{$role}' tidak dikenal. Peran yang valid: ".implode(', ', UserRole::values()).'.'
            ),
            $requested,
        );

        // Tanpa peran yang diminta, tidak ada pembatasan.
        if ($allowed !== [] && ! $user->hasRole(...$allowed)) {
            abort(403, 'Anda tidak memiliki akses ke bagian ini.');
        }

        return $next($request);
    }
}
