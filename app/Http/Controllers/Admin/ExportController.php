<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Excel\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function index(): View
    {
        $this->authorize('export', Company::class);

        return view('admin.exports', [
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'years' => range(now()->year, now()->year - 4),
            'months' => ExportService::months(),
        ]);
    }

    public function download(Request $request, ExportService $exports, string $type): Response
    {
        $this->authorize('export', Company::class);
        abort_unless(in_array($type, ['entries', 'summary', 'pnl'], true), 404);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'company' => [$type === 'pnl' ? 'required' : 'nullable', 'integer', Rule::exists('companies', 'id')],
            'format' => ['required', Rule::in($type === 'entries' ? ['xlsx', 'csv'] : ['xlsx', 'csv', 'pdf'])],
        ]);
        $company = isset($data['company']) ? Company::find($data['company']) : null;

        $table = match ($type) {
            'entries' => $exports->entries($company, (int) $data['year'], isset($data['month']) ? (int) $data['month'] : null),
            'summary' => $exports->summary((int) $data['year']),
            'pnl' => $exports->pnl($company, (int) $data['year']),
        };
        $filename = Str::slug(($company?->code ?? 'HESB').'-'.$type.'-'.$data['year'].(isset($data['month']) ? '-'.$data['month'] : ''));

        AuditLog::record('export.'.$type, null, ['company_id' => $company?->id, 'new' => "{$filename}.{$data['format']}"]);

        return $exports->download($table, $data['format'], $filename);
    }
}
