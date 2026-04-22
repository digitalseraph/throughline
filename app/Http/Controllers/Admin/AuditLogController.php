<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = AdminActivityLog::with('admin:id,email')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->whereRaw('action ilike ?', ['%'.$request->input('action').'%']);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $logs = $query->paginate(50)->withQueryString();

        return Inertia::render('admin/Audit', [
            'logs' => $logs,
            'filters' => $request->only(['action', 'from', 'to']),
        ]);
    }
}
