<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The company a request operates on.
     * Company users: ALWAYS their own company, whatever the URL or body says.
     * Admin / CEO: the {company} route parameter, policy-checked.
     */
    protected function resolveCompany(Request $request, ?Company $company = null): Company
    {
        $user = $request->user();

        if ($user->isCompanyUser()) {
            $company = $user->company;
        } elseif ($company !== null && ! $company->exists) {
            // Container-built empty model on a route without {company}.
            $company = null;
        }

        abort_if($company === null, 404);
        $this->authorize('view', $company);

        return $company;
    }

    /** ?year= from the query string, validated, defaulting to this year. */
    protected function year(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        return ($year >= 2000 && $year <= 2100) ? $year : (int) now()->year;
    }

    /** Years that have data, plus the current year, newest first. */
    protected function years($entryQuery): array
    {
        return collect($entryQuery->distinct()->pluck('year'))
            ->push((int) now()->year)->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }
}
