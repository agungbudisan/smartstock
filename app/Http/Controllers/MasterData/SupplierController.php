<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ])['search'] ?? null;

        $query = Supplier::query()->withCount('products');

        if (! empty($search)) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $query->orderBy('name')->get()
                ->map(fn (Supplier $supplier): array => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'contact_person' => $supplier->contact_person,
                    'phone' => $supplier->phone,
                    'address' => $supplier->address,
                    'products_count' => $supplier->products_count,
                ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        Supplier::create($request->validated());

        return back()->with('success', 'Pemasok berhasil ditambahkan.');
    }

    public function update(StoreSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        return back()->with('success', 'Pemasok berhasil diperbarui.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $name = $supplier->name;
        $supplier->delete();

        return back()->with('success', "Pemasok {$name} dihapus. Riwayat movement tetap tersimpan.");
    }
}
