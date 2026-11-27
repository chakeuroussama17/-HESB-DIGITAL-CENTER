<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Upload;
use App\Services\Excel\AmpWorkbook;
use App\Services\Finance\AmpService;
use App\Support\Money;
use App\Support\PortalRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMP per company and year. Company users and the CEO see it read-only; HESB Admin
 * edits it in the grid or imports Excel/CSV (staged for review, then saved).
 */
class AmpController extends Controller
{
    public function show(Request $request, AmpService $amp, ?Company $company = null, $year = null): View
    {
        $company = $this->resolveCompany($request, $company);
        $year = $year ? (int) $year : (int) now()->year;

        $staged = $request->session()->get('amp_staged');
        $stagedCells = ($staged && $staged['key'] === "{$company->id}:{$year}") ? $staged['cells'] : [];

        return view('amp.show', $amp->grid($company, $year, $stagedCells) + [
            'company' => $company,
            'year' => $year,
            'canEdit' => $request->user()->can('manageAmp', $company),
            'warnings' => $request->session()->get('amp_warnings', []),
            'url' => fn (string $name, array $p = []) => PortalRoute::to($name, $company, $p),
        ]);
    }

    public function update(Request $request, AmpService $amp, Company $company, $year): RedirectResponse
    {
        $this->authorize('manageAmp', $company);
        $request->validate([
            'amp' => ['required', 'array', 'max:200'],
            'amp.*' => ['array', 'max:12'],
            'amp.*.*' => ['nullable', 'max:24', function ($attr, $value, $fail) {
                if ($value !== null && ! Money::isValid($value)) {
                    $fail('Each AMP amount must be a number with up to 2 decimals.');
                }
            }],
        ]);

        $changed = $amp->save($company, (int) $year, $request->input('amp'), $request->user());
        $request->session()->forget('amp_staged');

        return redirect()->route('group.amp.show', ['company' => $company->id, 'year' => $year])
            ->with('status', $changed ? "AMP saved. {$changed} ".Str::plural('target', $changed).' updated.' : 'No changes to save.');
    }

    public function template(Request $request, AmpWorkbook $workbook, ?Company $company = null, $year = null): StreamedResponse
    {
        $company = $this->resolveCompany($request, $company);
        $year = $year ? (int) $year : (int) now()->year;
        $book = $workbook->template($company, $year);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), "{$company->code}_AMP_{$year}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request, AmpWorkbook $workbook, Company $company, $year): RedirectResponse
    {
        $this->authorize('manageAmp', $company);
        $year = (int) $year;
        $back = route('group.amp.show', ['company' => $company->id, 'year' => $year]);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']]);

        $file = $request->file('file');
        $path = $file->storeAs("uploads/{$company->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        $result = $workbook->parse(Storage::disk('local')->path($path), $company, $year);
        if ($result['errors']) {
            return redirect($back)->withErrors(['file' => $result['errors']]);
        }

        $count = array_sum(array_map('count', $result['cells']));
        $upload = Upload::create([
            'company_id' => $company->id, 'user_id' => $request->user()->id, 'kind' => 'amp', 'year' => $year,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'path' => $path, 'cells_changed' => $count,
        ]);
        AuditLog::record('amp.upload', $upload, ['company_id' => $company->id, 'new' => $upload->original_name]);

        return redirect($back)
            ->with('amp_staged', ['key' => "{$company->id}:{$year}", 'cells' => $result['cells']])
            ->with('amp_warnings', $result['warnings'])
            ->with('status', "{$count} AMP ".Str::plural('value', $count)." loaded from {$upload->original_name}. Review the highlighted cells, then press Save.");
    }
}
