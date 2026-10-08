<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jejak rekam aktivitas (PRD bagian 3: Audit Sistem).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where('description', 'like', "%{$search}%");
            })
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at->toIso8601String(),
                'user' => $log->user?->only(['id', 'name']),
            ]);

        return Inertia::render('Inventory/AuditLogs', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => AuditLog::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
        ]);
    }
}
