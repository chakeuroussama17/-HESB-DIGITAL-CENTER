<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveEntriesRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Entry;
use App\Models\Period;
use App\Models\Upload;
use App\Services\Excel\EntryWorkbook;
use App\Services\Finance\EntryGrid;
use App\Services\Finance\EntryService;
use App\Support\Money;
use App\Support\PortalRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Monthly "Manage Forecast & Actual" grid, Excel template and Excel upload. */
class EntryController extends Controller
{
    public function edit(Request $request, EntryGrid $grid, ?Company $company = null, $year = null, $month = null): View
    {
        $company = $this->resolveCompany($request, $company);
        [$year, $month] = $this->period($year, $month);

        $staged = $request->session()->get('staged');
        $stagedCells = ($staged && $staged['key'] === $this->stageKey($company, $year, $month)) ? $staged['cells'] : [];

        $data = $grid->build($company, $year, $month, $stagedCells);
        $data['canEdit'] = $request->user()->can('enterData', $company) && ! $data['locked'];
        $data['canManageLines'] = $request->user()->can('manageLines', $company);
        $data['uploadWarnings'] = $request->session()->get('upload_warnings', []);
        $data['url'] = fn (string $name, array $p = []) => PortalRoute::to($name, $company, $p);

        return view('entries.edit', $data);
    }

    public function update(SaveEntriesRequest $request, EntryService $service, ?Company $company = null, $year = null, $month = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('enterData', $company);
        [$year, $month] = $this->period($year, $month);

        $changed = $service->saveMonth($company, $year, $month, $request->validated('cells'), $request->user());
        $request->session()->forget('staged');

        return redirect(PortalRoute::to('entries.edit', $company, ['year' => $year, 'month' => $month]))
            ->with('status', $changed ? "Saved. {$changed} ".Str::plural('value', $changed).' updated.' : 'No changes to save.');
    }

    public function template(Request $request, EntryWorkbook $workbook, ?Company $company = null, $year = null, $month = null): StreamedResponse
    {
        $company = $this->resolveCompany($request, $company);
        [$year, $month] = $this->period($year, $month);

        $book = $workbook->template($company, $year, $month);
        $name = sprintf('%s_%04d-%02d_weekly.xlsx', $company->code, $year, $month);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Upload a filled workbook: values are parsed and "staged" into the grid
     * (highlighted) for review; nothing is saved until the user clicks Save.
     */
    public function upload(Request $request, EntryWorkbook $workbook, ?Company $company = null, $year = null, $month = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('enterData', $company);
        [$year, $month] = $this->period($year, $month);
        $back = PortalRoute::to('entries.edit', $company, ['year' => $year, 'month' => $month]);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'],
        ], ['file.mimes' => 'Upload an Excel (.xlsx / .xls) or CSV file.']);

        if (Period::isLocked($company->id, $year, $month)) {
            return redirect($back)->withErrors(['file' => 'This month is locked; uploads are disabled.']);
        }

        $file = $request->file('file');
        $path = $file->storeAs("uploads/{$company->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        $result = $workbook->parse(Storage::disk('local')->path($path), $company, $year, $month);

        if ($result['errors']) {
            return redirect($back)->withErrors(['file' => $result['errors']]);
        }

        // Only stage values that differ from what is already saved.
        $existing = Entry::where(['company_id' => $company->id, 'year' => $year, 'month' => $month])->get()
            ->keyBy(fn ($e) => $e->financial_line_id.'.'.$e->week);
        $staged = [];
        foreach ($result['cells'] as $lineId => $byWeek) {
            foreach ($byWeek as $week => $byKind) {
                foreach ($byKind as $kind => $value) {
                    $old = $existing->get("{$lineId}.{$week}")?->{$kind};
                    if ($old === null || Money::cmp($old, $value) !== 0) {
                        $staged[$lineId][$week][$kind] = $value;
                    }
                }
            }
        }
        $count = array_sum(array_map(fn ($w) => array_sum(array_map('count', $w)), $staged));

        $upload = Upload::create([
            'company_id' => $company->id, 'user_id' => $request->user()->id, 'kind' => 'entries',
            'year' => $year, 'month' => $month, 'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'path' => $path, 'cells_changed' => $count,
        ]);
        AuditLog::record('entries.upload', $upload, ['company_id' => $company->id, 'new' => $upload->original_name, 'context' => ['cells' => $count]]);

        if ($count === 0) {
            return redirect($back)->with('status', 'The file matches what is already saved. Nothing to change.')
                ->with('upload_warnings', $result['warnings']);
        }

        return redirect($back)
            ->with('staged', ['key' => $this->stageKey($company, $year, $month), 'cells' => $staged])
            ->with('upload_warnings', $result['warnings'])
            ->with('status', "{$count} ".Str::plural('cell', $count)." filled from {$upload->original_name}. Check the highlighted cells, then press Save.");
    }

    /** @return array{0:int,1:int} */
    private function period($year, $month): array
    {
        $year = $year === null ? (int) now()->year : (int) $year;
        $month = $month === null ? (int) now()->month : (int) $month;
        abort_unless($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12, 404);

        return [$year, $month];
    }

    private function stageKey(Company $company, int $year, int $month): string
    {
        return "{$company->id}:{$year}:{$month}";
    }
}
