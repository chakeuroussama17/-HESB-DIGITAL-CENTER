<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Entry;
use App\Services\Finance\FinanceGuru;
use App\Services\Finance\PerformanceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyDashboardController extends Controller
{
    public function show(Request $request, PerformanceService $performance, FinanceGuru $guru, ?Company $company = null): View
    {
        $company = $this->resolveCompany($request, $company);
        $year = $this->year($request);

        $data = $performance->dashboard(collect([$company]), $year);

        return view('company.dashboard', $data + [
            'company' => $company,
            'advice' => $guru->advise($data),
            'years' => $this->years(Entry::where('company_id', $company->id)),
        ]);
    }
}
