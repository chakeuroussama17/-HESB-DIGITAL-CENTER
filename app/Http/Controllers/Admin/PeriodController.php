<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Lock / unlock months, for all companies at once or one company. */
class PeriodController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('administer');
        $year = $this->year($request);
        $companies = Company::active()->orderBy('name')->get();
        $periods = Period::where('year', $year)->get()->keyBy(fn ($p) => ($p->company_id ?? 'all').'.'.$p->month);

        return view('admin.periods', compact('year', 'companies', 'periods') + [
            'years' => range(now()->year + 1, now()->year - 3),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('administer');
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'locked' => ['required', 'boolean'],
        ]);

        $period = Period::updateOrCreate(
            ['company_id' => $data['company_id'] ?? null, 'year' => $data['year'], 'month' => $data['month']],
            ['is_locked' => $data['locked'], 'locked_by' => $request->user()->id, 'locked_at' => now()],
        );
        // A group-wide change resets company-specific overrides for that month.
        if (empty($data['company_id'])) {
            Period::whereNotNull('company_id')->where(['year' => $data['year'], 'month' => $data['month']])->delete();
        }
        AuditLog::record($data['locked'] ? 'period.locked' : 'period.unlocked', $period, [
            'company_id' => $data['company_id'] ?? null,
            'context' => ['year' => $data['year'], 'month' => $data['month'], 'scope' => empty($data['company_id']) ? 'all' : 'company'],
        ]);

        $label = CarbonImmutable::create($data['year'], $data['month'])->format('F Y');
        $who = empty($data['company_id']) ? 'all companies' : Company::find($data['company_id'])->name;

        return back()->with('status', "{$label} ".($data['locked'] ? 'locked' : 'unlocked')." for {$who}.");
    }
}
