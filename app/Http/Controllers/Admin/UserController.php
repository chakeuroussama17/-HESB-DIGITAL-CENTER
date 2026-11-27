<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);
        $q = trim((string) $request->query('q'));

        $users = User::with('company')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderByDesc('is_active')->orderBy('role')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.users.index', compact('users', 'q'));
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.form', ['user' => new User(['role' => Role::CompanyUser, 'is_active' => true]), 'companies' => Company::orderBy('name')->get()]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $user = User::create($request->validated() + ['email_verified_at' => now()]);
        AuditLog::record('user.created', $user, ['company_id' => $user->company_id, 'new' => "{$user->email} ({$user->role->value})"]);

        return redirect()->route('admin.users.index')->with('status', "{$user->name} can now sign in.");
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.form', ['user' => $user, 'companies' => Company::orderBy('name')->get()]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }

        // Don't let the last active admin lock themselves (and everyone) out.
        $demoting = $user->isAdmin() && (($data['role'] ?? null) !== Role::Admin->value || ! $data['is_active']);
        if ($demoting && User::where('role', Role::Admin)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['role' => 'There must be at least one active HESB Admin.'])->withInput();
        }

        $before = "{$user->role->value}/".($user->company_id ?? '-').'/'.($user->is_active ? 'active' : 'inactive');
        $user->update($data);
        AuditLog::record('user.updated', $user, [
            'company_id' => $user->company_id, 'old' => $before,
            'new' => "{$user->role->value}/".($user->company_id ?? '-').'/'.($user->is_active ? 'active' : 'inactive').(isset($data['password']) ? ' +password' : ''),
        ]);

        return redirect()->route('admin.users.index')->with('status', "{$user->name} updated.");
    }
}
