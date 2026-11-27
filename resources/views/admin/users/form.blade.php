@php use App\Enums\Role; @endphp
<x-layouts.app :title="$user->exists ? 'Edit '.$user->name : 'Add user'" section="Administration">
    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card max-w-2xl space-y-5" data-user-form>
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="name" class="label">Full name</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" class="field">
            </div>
            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required class="field" autocomplete="off">
            </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="role" class="label">Role</label>
                <select id="role" name="role" class="field" data-role>
                    @foreach (Role::cases() as $r)
                        <option value="{{ $r->value }}" @selected(old('role', $user->role?->value) === $r->value)>{{ $r->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-ink-muted">CEO: every dashboard, read-only. Admin: full edit rights.</p>
            </div>
            <div data-company-field>
                <label for="company_id" class="label">Company</label>
                <select id="company_id" name="company_id" class="field">
                    <option value="">Choose…</option>
                    @foreach ($companies as $c)
                        <option value="{{ $c->id }}" @selected((int) old('company_id', $user->company_id) === $c->id)>{{ $c->name }}{{ $c->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-ink-muted">Company users see and enter only this company's data.</p>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="password" class="label">{{ $user->exists ? 'New password (optional)' : 'Password' }}</label>
                <input id="password" name="password" type="password" @if (! $user->exists) required @endif autocomplete="new-password" class="field">
                <p class="mt-1 text-xs text-ink-muted">At least 10 characters with letters and numbers.</p>
            </div>
            <div>
                <label for="password_confirmation" class="label">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="field">
            </div>
        </div>
        <label class="flex items-center gap-3 text-sm cursor-pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="size-4 accent-brand-300">
            Active (can sign in)
        </label>
        <div class="flex gap-3 pt-2">
            <button class="btn-primary">{{ $user->exists ? 'Save changes' : 'Create user' }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
    <script>
        (() => {
            const role = document.querySelector('[data-role]');
            const field = document.querySelector('[data-company-field]');
            const sync = () => field.hidden = role.value !== 'company_user';
            role.addEventListener('change', sync); sync();
        })();
    </script>
</x-layouts.app>
