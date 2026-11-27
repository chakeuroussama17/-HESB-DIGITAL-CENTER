<?php

namespace App\Support;

use App\Models\Company;

/**
 * Company users use routes without a company id (/entries/2026/11); admin and CEO
 * use the /group/companies/{company}/... twin. Views call PortalRoute::to() so the
 * same template works for both.
 */
final class PortalRoute
{
    public static function to(string $name, Company $company, array $params = []): string
    {
        $user = auth()->user();

        return $user && $user->isCompanyUser()
            ? route($name, $params)
            : route("group.{$name}", ['company' => $company->id] + $params);
    }
}
