<x-layouts.app title="Companies" section="Administration">
    <x-slot:actions>
        <a href="{{ route('admin.companies.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> Add company</a>
    </x-slot:actions>

    <section class="card !p-0 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="th !pl-[22px]">Company</th>
                    <th class="th">Code</th>
                    <th class="th">Users</th>
                    <th class="th">Status</th>
                    <th class="th !pr-[22px]"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($companies as $c)
                    <tr class="{{ $c->is_active ? '' : 'opacity-60' }}">
                        <td class="td !pl-[22px]">
                            <span class="flex items-center gap-3 font-bold">
                                <span class="grid place-items-center size-9 rounded-control text-white text-xs font-black" style="background: {{ $c->color }}">{{ mb_substr($c->code, 0, 2) }}</span>
                                {{ $c->name }}
                            </span>
                        </td>
                        <td class="td font-mono text-xs">{{ $c->code }}</td>
                        <td class="td">{{ $c->users_count }}</td>
                        <td class="td">@if ($c->is_active)<span class="badge-good">Active</span>@else<span class="badge-muted">Inactive</span>@endif</td>
                        <td class="td !pr-[22px] text-right whitespace-nowrap">
                            <a href="{{ route('group.companies.dashboard', $c) }}" class="btn-ghost !h-8 !px-3">Dashboard</a>
                            <a href="{{ route('admin.companies.edit', $c) }}" class="btn-ghost !h-8 !px-3">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
