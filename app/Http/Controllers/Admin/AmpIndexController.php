<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\FinancialLine;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Which companies have a complete AMP for the year, with links into each. */
class AmpIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('administer');
        $year = $this->year($request);
        $revenueId = FinancialLine::standard()->where('code', 'revenue')->value('id');

        $companies = Company::active()->orderBy('name')->get()->map(function (Company $c) use ($year, $revenueId) {
            $targets = AmpTarget::where(['company_id' => $c->id, 'year' => $year])->get();

            return [
                'company' => $c,
                'months' => $targets->pluck('month')->unique()->count(),
                'revenue' => Money::add(...$targets->where('financial_line_id', $revenueId)->pluck('amount')->all()),
                'updated' => $targets->max('updated_at'),
            ];
        });

        return view('admin.amp-index', ['year' => $year, 'companies' => $companies, 'years' => range(now()->year + 1, now()->year - 3)]);
    }
}
