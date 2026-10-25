<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Admin: system
 */
class AuditLogController extends Controller
{
    /**
     * Audit log (SEC-9)
     *
     * @queryParam action string Action prefix, e.g. `auth.` or `grade.released`. Example: auth.
     * @queryParam user_id integer Actor. Example: 1
     * @queryParam from date ISO date (inclusive). Example: 2026-10-01
     * @queryParam to date ISO date (inclusive). Example: 2026-10-31
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $logs = AuditLog::with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $request->string('action')).'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return AuditLogResource::collection($logs);
    }
}
