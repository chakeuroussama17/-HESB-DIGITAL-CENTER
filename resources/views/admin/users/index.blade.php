<x-layouts.app title="Users" section="Administration">
    <x-slot:actions>
        <a href="{{ route('admin.users.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> Add user</a>
    </x-slot:actions>

    <form method="GET" class="mb-4 max-w-sm">
        <input name="q" value="{{ $q }}" class="field-sm" placeholder="Search name or email" aria-label="Search users">
    </form>

    <section class="card !p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">User</th>
                        <th class="th">Role</th>
                        <th class="th">Company</th>
                        <th class="th">Last sign-in</th>
                        <th class="th">Status</th>
                        <th class="th !pr-[22px]"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="{{ $u->is_active ? '' : 'opacity-60' }}">
                            <td class="td !pl-[22px]">
                                <p class="font-bold">{{ $u->name }}</p>
                                <p class="text-xs text-ink-muted">{{ $u->email }}</p>
                            </td>
                            <td class="td">
                                <span class="{{ ['hesb_admin' => 'badge-brand', 'ceo' => 'badge-warn', 'company_user' => 'badge-muted'][$u->role->value] }}">{{ $u->role->label() }}</span>
                            </td>
                            <td class="td">{{ $u->company?->name ?? '— all —' }}</td>
                            <td class="td text-xs text-ink-muted">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="td">@if ($u->is_active)<span class="badge-good">Active</span>@else<span class="badge-muted">Disabled</span>@endif</td>
                            <td class="td !pr-[22px] text-right"><a href="{{ route('admin.users.edit', $u) }}" class="btn-ghost !h-8 !px-3">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="td text-center text-ink-muted">No users match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts.app>
