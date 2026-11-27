<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(): View
    {
        $this->authorize('create', Company::class);

        return view('admin.companies.index', [
            'companies' => Company::withCount('users')->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Company::class);

        return view('admin.companies.form', ['company' => new Company(['color' => '#4FD1C5', 'is_active' => true])]);
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $this->authorize('create', Company::class);
        $company = Company::create($request->validated());
        AuditLog::record('company.created', $company, ['company_id' => $company->id, 'new' => $company->name]);

        return redirect()->route('admin.companies.index')->with('status', "{$company->name} added. Create a user for it next.");
    }

    public function edit(Company $company): View
    {
        $this->authorize('update', $company);

        return view('admin.companies.form', compact('company'));
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $this->authorize('update', $company);
        $before = $company->only(['name', 'code', 'color', 'is_active']);
        $company->update($request->validated());
        AuditLog::record('company.updated', $company, [
            'company_id' => $company->id, 'old' => json_encode($before), 'new' => json_encode($company->only(array_keys($before))),
        ]);

        return redirect()->route('admin.companies.index')->with('status', "{$company->name} updated.");
    }
}
