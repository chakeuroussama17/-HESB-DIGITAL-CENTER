<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('administer');
        $filters = $request->validate([
            'company' => ['nullable', 'integer'],
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::with(['user:id,name', 'company:id,name,code'])
            ->when($filters['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'filters' => $filters,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
