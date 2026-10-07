<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Titik masuk setelah login: mengarahkan tiap peran ke modul yang relevan
 * (PRD bagian 2 — tiap aktor punya antarmuka berbeda).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Manager Bisnis -> dasbor analitik; Admin Gudang -> operasional stok.
        if ($user->isManagerBisnis()) {
            return redirect()->route('analytics.dashboard');
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'low_stock' => Product::needingReorder()->count(),
                'out_of_stock' => Product::where('current_stock', 0)->count(),
                'movements_today' => InventoryMovement::whereDate('movement_date', now())->count(),
            ],
            'role' => $user->role->value,
        ]);
    }
}
