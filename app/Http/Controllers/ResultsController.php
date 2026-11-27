<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\MonthlyResult;
use App\Models\Upload;
use App\Services\Excel\MonthlyResultsWorkbook;
use App\Services\Finance\MonthlyResultService;
use App\Support\PortalRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Monthly results sheets ("HA Rev / HA PBT / HA EBITDA" with AMP + Act per month).
 * Upload → preview of old/new values → confirm. Figures are stored as reported.
 */
class ResultsController extends Controller
{
    public function show(Request $request, MonthlyResultService $service, ?Company $company = null, $year = null): View
    {
        $company = $this->resolveCompany($request, $company);
        $year = $year ? (int) $year : (int) now()->year;

        $preview = null;
        $pending = $request->session()->get('results_preview');
        if ($pending && $pending['company'] === $company->id) {
            $preview = $service->preview($company, $pending['data']) + ['warnings' => $pending['warnings'], 'file' => $pending['file']];
        }

        $results = MonthlyResult::where(['company_id' => $company->id, 'year' => $year])->get()
            ->groupBy('metric')->map(fn ($rows) => $rows->keyBy('month'));
        $metrics = array_values(array_filter(array_keys(MonthlyResult::METRICS), fn ($m) => $results->has($m)));

        return view('results.show', [
            'company' => $company,
            'year' => $year,
            'results' => $results,
            'metrics' => $metrics,
            'preview' => $preview,
            'canUpload' => $request->user()->can('uploadResults', $company),
            'lastUpload' => Upload::where(['company_id' => $company->id, 'kind' => 'results'])->latest('id')->first(),
            'url' => fn (string $name, array $p = []) => PortalRoute::to($name, $company, $p),
        ]);
    }

    public function upload(Request $request, MonthlyResultsWorkbook $workbook, ?Company $company = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('uploadResults', $company);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']],
            ['file.mimes' => 'Upload an Excel (.xlsx / .xls) or CSV file.']);

        $file = $request->file('file');
        $path = $file->storeAs("uploads/{$company->id}", Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        $parsed = $workbook->parse(Storage::disk('local')->path($path));
        $back = PortalRoute::to('results.show', $company);

        if ($parsed['errors']) {
            return redirect($back)->withErrors(['file' => $parsed['errors']]);
        }

        $years = array_keys($parsed['data']);
        $upload = Upload::create([
            'company_id' => $company->id, 'user_id' => $request->user()->id, 'kind' => 'results',
            'year' => $years[0], 'original_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'path' => $path,
        ]);

        $request->session()->put('results_preview', [
            'company' => $company->id, 'data' => $parsed['data'], 'upload' => $upload->id,
            'warnings' => $parsed['warnings'], 'file' => $upload->original_name,
        ]);

        return redirect(PortalRoute::to('results.show', $company, ['year' => end($years)]));
    }

    public function confirm(Request $request, MonthlyResultService $service, ?Company $company = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('uploadResults', $company);

        $pending = $request->session()->pull('results_preview');
        abort_unless($pending && $pending['company'] === $company->id, 422, 'Nothing to confirm. Upload the file again.');

        $changed = $service->save($company, $pending['data'], $request->user(), $pending['upload']);
        Upload::whereKey($pending['upload'])->update(['cells_changed' => $changed]);
        AuditLog::record('results.confirmed', null, ['company_id' => $company->id, 'new' => $pending['file'], 'context' => ['values' => $changed]]);

        $year = array_key_last($pending['data']);

        return redirect(PortalRoute::to('results.show', $company, ['year' => $year]))
            ->with('status', $changed ? "Saved {$changed} ".Str::plural('figure', $changed)." from {$pending['file']}. Dashboards now use these figures." : 'Nothing changed: the file matches what is already saved.');
    }

    public function cancel(Request $request, ?Company $company = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $request->session()->forget('results_preview');

        return redirect(PortalRoute::to('results.show', $company))->with('status', 'Upload discarded.');
    }
}
