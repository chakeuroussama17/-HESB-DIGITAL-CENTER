<?php

namespace App\Http\Controllers\Group;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Entry;
use App\Services\Finance\FinanceGuru;
use App\Services\Finance\PerformanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupDashboardController extends Controller
{
    public function show(Request $request, PerformanceService $performance, FinanceGuru $guru): View
    {
        $this->authorize('view-group');
        $year = $this->year($request);
        $companies = Company::active()->orderBy('name')->get();

        $data = $performance->dashboard($companies, $year);
        $scoreboard = $companies->map(fn (Company $c) => $performance->headline($c, $year))
            ->sortByDesc(fn ($h) => (float) ($h['kpis']['total_revenue']['pct'] ?? -1))->values();

        return view('group.dashboard', $data + [
            'companies' => $companies,
            'scoreboard' => $scoreboard,
            'advice' => $guru->advise($data, group: true),
            'years' => $this->years(Entry::query()),
        ]);
    }
}
