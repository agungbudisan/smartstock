<?php

use App\Http\Controllers\Analytics\AccuracyController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\AuditLogController;
use App\Http\Controllers\Inventory\MovementController;
use App\Http\Controllers\MasterData\CategoryController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\ProductSupplierController;
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
})->name('home');

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Area terotentikasi
|--------------------------------------------------------------------------
| Setiap pengguna login diarahkan sesuai perannya oleh DashboardController.
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Master data — Admin Gudang dan Manager, keduanya boleh menulis
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'role:admin_gudang,manager_bisnis'])->group(function (): void {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::get('/products/{product}/suppliers', [ProductSupplierController::class, 'index'])->name('products.suppliers.index');
    Route::post('/products/{product}/suppliers', [ProductSupplierController::class, 'store'])->name('products.suppliers.store');
    Route::put('/products/{product}/suppliers', [ProductSupplierController::class, 'sync'])->name('products.suppliers.sync');
    Route::patch('/products/{product}/suppliers/{supplier}', [ProductSupplierController::class, 'update'])->name('products.suppliers.update');
    Route::delete('/products/{product}/suppliers/{supplier}', [ProductSupplierController::class, 'destroy'])->name('products.suppliers.destroy');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/products/{product}/restore', [ProductController::class, 'restore'])->name('products.restore');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
});

/*
|--------------------------------------------------------------------------
| Buku Besar & jejak audit — DUA PERAN, baca saja
|--------------------------------------------------------------------------
| Manager boleh membaca ledger karena ledger adalah jejak audit: tanpa itu,
| pertanyaan "stok ini naik 40 tanpa ada PO, kenapa?" tidak punya jawaban.
| Tapi ia tidak boleh mencatat, jadi halaman yang MEMBAWA form entri tidak
| boleh terbuka untuknya — form yang tidak bisa disimpan hanya menambah langkah
| sia-sia sebelum 403.
*/
Route::middleware(['auth', 'verified', 'role:admin_gudang,manager_bisnis'])->group(function (): void {
    Route::get('/inventory/ledger', [MovementController::class, 'ledger'])->name('inventory.ledger');
    Route::get('/inventory/audit-logs', [AuditLogController::class, 'index'])->name('inventory.audit-logs.index');
});

/*
|--------------------------------------------------------------------------
| Entri pergerakan — hanya Admin Gudang
|--------------------------------------------------------------------------
| Halaman `/inventory/movements` menampilkan form entri, jadi ia ikut teratasi
| di sini. Halaman yang Manager gunakan adalah `/inventory/ledger` di atas.
*/
Route::middleware(['auth', 'verified', 'role:admin_gudang'])->group(function (): void {
    Route::get('/inventory/movements', [MovementController::class, 'index'])->name('inventory.movements.index');
    Route::post('/inventory/movements', [MovementController::class, 'store'])->name('inventory.movements.store');
});

/*
|--------------------------------------------------------------------------
| Dasbor Analitik — Manager Bisnis
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified', 'role:manager_bisnis'])->group(function (): void {
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.dashboard');
    Route::get('/analytics/reorder', [AnalyticsController::class, 'reorder'])->name('analytics.reorder');
    Route::get('/analytics/reorder.csv', [AnalyticsController::class, 'exportCsv'])->name('analytics.reorder.csv');

    // Evaluasi akurasi & ekspor laporan prediksi (PRD Modul 3).
    Route::get('/analytics/accuracy', [AccuracyController::class, 'index'])->name('analytics.accuracy');
    Route::get('/analytics/accuracy.csv', [AccuracyController::class, 'exportCsv'])->name('analytics.accuracy.csv');
});

// Profil (Breeze) — tersedia untuk semua pengguna.
Route::middleware('auth')->group(function (): void {
    // Notifikasi (lonceng di AppLayout).
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
    Route::delete('/notifications/read', [NotificationController::class, 'clearRead'])->name('notifications.clear-read');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
