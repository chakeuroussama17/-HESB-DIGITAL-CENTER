<?php

namespace App\Http\Controllers;

use App\Enums\LineType;
use App\Http\Requests\StoreLineRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** "+ Add Row": custom input lines owned by one company. */
class FinancialLineController extends Controller
{
    public function store(StoreLineRequest $request, ?Company $company = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('manageLines', $company);

        $group = $request->validated('group');
        // Slot the new row after the last line of its group so it sits under the right subtotal.
        $sort = (int) FinancialLine::forCompany($company->id)->where('group', $group)->max('sort') + 1;

        $line = FinancialLine::create([
            'company_id' => $company->id,
            'code' => 'custom_'.Str::limit(Str::slug($request->validated('label'), '_'), 30, '').'_'.Str::lower(Str::random(4)),
            'label' => $request->validated('label'),
            'type' => LineType::Input,
            'group' => $group,
            'sort' => $sort,
        ]);
        AuditLog::record('line.created', $line, ['new' => $line->label, 'context' => ['group' => $group]]);

        return back()->with('status', "Row \"{$line->label}\" added.");
    }

    public function destroy(Request $request, FinancialLine $line, ?Company $company = null): RedirectResponse
    {
        $company = $this->resolveCompany($request, $company);
        $this->authorize('manageLines', $company);
        abort_unless($line->company_id === $company->id, 404); // standard lines and other companies' lines

        $hasData = Entry::where('financial_line_id', $line->id)
            ->where(fn ($q) => $q->whereNotNull('actual')->orWhereNotNull('forecast'))->exists();

        if ($hasData) {
            $line->update(['is_active' => false]); // keep history, hide from grid
        } else {
            $line->delete();
        }
        AuditLog::record($hasData ? 'line.archived' : 'line.deleted', $line, ['company_id' => $company->id, 'old' => $line->label]);

        return back()->with('status', "Row \"{$line->label}\" removed.");
    }
}
