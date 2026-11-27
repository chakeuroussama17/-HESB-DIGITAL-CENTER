<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * All financial data hangs off a company, so this one policy guards it:
 *  - company_user: own company only, may enter actuals/forecasts and add custom rows.
 *  - hesb_admin:   everything.
 *  - ceo:          view everything, change nothing.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGroupLevel();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canViewCompany($company);
    }

    /** Enter / upload weekly actuals and forecasts. Lock checks happen in the service. */
    public function enterData(User $user, Company $company): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isCompanyUser()
            && $user->company_id === $company->id
            && $company->is_active;
    }

    /** Add / rename / remove custom "+ Add Row" lines. */
    public function manageLines(User $user, Company $company): bool
    {
        return $this->enterData($user, $company);
    }

    /**
     * Upload a monthly results sheet (reported AMP + Actual totals).
     * Admin for any company, a company user for their own; never the CEO.
     */
    public function uploadResults(User $user, Company $company): bool
    {
        return $this->enterData($user, $company);
    }

    /** Set Annual Management Plan targets. */
    public function manageAmp(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->isAdmin();
    }
}
