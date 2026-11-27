<x-layouts.app :title="$company->exists ? 'Edit '.$company->name : 'Add company'" section="Administration">
    <form method="POST" action="{{ $company->exists ? route('admin.companies.update', $company) : route('admin.companies.store') }}" class="card max-w-2xl space-y-5">
        @csrf
        @if ($company->exists) @method('PUT') @endif
        <div>
            <label for="name" class="label">Company name</label>
            <input id="name" name="name" value="{{ old('name', $company->name) }}" required maxlength="120" class="field">
        </div>
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="code" class="label">Short code</label>
                <input id="code" name="code" value="{{ old('code', $company->code) }}" required maxlength="20" class="field uppercase font-mono" placeholder="e.g. HAUTO">
                <p class="mt-1 text-xs text-ink-muted">Used in file names and Excel templates.</p>
            </div>
            <div>
                <label for="color" class="label">Chart colour</label>
                <div class="flex items-center gap-3">
                    <input id="color" name="color" type="color" value="{{ old('color', $company->color) }}" class="h-[50px] w-20 rounded-card border border-line bg-field p-1 cursor-pointer">
                    <span class="text-xs text-ink-muted">Identifies the company in charts and lists.</span>
                </div>
            </div>
        </div>
        <label class="flex items-center gap-3 text-sm cursor-pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $company->is_active)) class="size-4 accent-brand-300">
            Active. Inactive companies disappear from dashboards and their users cannot sign in. Data is kept.
        </label>
        <div class="flex gap-3 pt-2">
            <button class="btn-primary">{{ $company->exists ? 'Save changes' : 'Add company' }}</button>
            <a href="{{ route('admin.companies.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
