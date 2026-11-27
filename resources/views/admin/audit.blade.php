@php
    $monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $describe = function ($log) use ($monthNames) {
        $c = $log->context ?? [];
        $when = isset($c['month']) ? ($monthNames[$c['month']] ?? $c['month']).' '.($c['year'] ?? '') : null;
        return trim(implode(' · ', array_filter([
            $c['line'] ?? null,
            isset($c['week']) ? 'Wk'.$c['week'] : null,
            $when,
            $log->field,
        ])));
    };
@endphp
<x-layouts.app title="Audit Log" section="Administration">
    <form method="GET" class="card grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
        <div>
            <label class="label !text-xs" for="a-company">Company</label>
            <select id="a-company" name="company" class="field-sm"><option value="">All</option>
                @foreach ($companies as $c)<option value="{{ $c->id }}" @selected(($filters['company'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label !text-xs" for="a-user">User</label>
            <select id="a-user" name="user" class="field-sm"><option value="">All</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected(($filters['user'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="label !text-xs" for="a-action">Action</label>
            <select id="a-action" name="action" class="field-sm"><option value="">All</option>
                @foreach ($actions as $a)<option @selected(($filters['action'] ?? null) === $a)>{{ $a }}</option>@endforeach
            </select>
        </div>
        <div><label class="label !text-xs" for="a-from">From</label><input id="a-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="field-sm"></div>
        <div><label class="label !text-xs" for="a-to">To</label><input id="a-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="field-sm"></div>
        <div class="flex gap-2"><button class="btn-primary">Filter</button><a href="{{ route('admin.audit.index') }}" class="btn-ghost">Reset</a></div>
    </form>

    <section class="mt-5 card !p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">When</th>
                        <th class="th">Who</th>
                        <th class="th">Company</th>
                        <th class="th">Action</th>
                        <th class="th">What</th>
                        <th class="th text-right">Old</th>
                        <th class="th text-right !pr-[22px]">New</th>
                    </tr>
                </thead>
                <tbody class="tabular">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="td !pl-[22px] whitespace-nowrap text-xs">{{ $log->created_at->format('j M Y H:i') }}</td>
                            <td class="td">{{ $log->user?->name ?? 'System' }}<span class="block text-[11px] text-ink-muted">{{ $log->ip_address }}</span></td>
                            <td class="td">{{ $log->company?->code ?? '–' }}</td>
                            <td class="td"><span class="badge-brand">{{ $log->action }}</span></td>
                            <td class="td text-xs text-ink-soft">{{ $describe($log) ?: '–' }}</td>
                            <td class="td text-right text-ink-muted text-xs max-w-[220px] truncate" title="{{ $log->old_value }}">{{ $log->old_value ?? '–' }}</td>
                            <td class="td text-right !pr-[22px] text-xs font-semibold max-w-[220px] truncate" title="{{ $log->new_value }}">{{ $log->new_value ?? '–' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="td text-center text-ink-muted">No activity matches these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.app>
