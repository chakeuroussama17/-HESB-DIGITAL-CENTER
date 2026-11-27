@php
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $groupLocked = fn ($m) => (bool) ($periods->get("all.{$m}")?->is_locked);
    $companyState = function ($cid, $m) use ($periods, $groupLocked) {
        $p = $periods->get("{$cid}.{$m}");
        return $p ? ['locked' => $p->is_locked, 'override' => true] : ['locked' => $groupLocked($m), 'override' => false];
    };
@endphp
<x-layouts.app title="Period Locks" section="Administration">
    <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="card !p-1 flex gap-1">
            @foreach ($years as $y)
                <button name="year" value="{{ $y }}" @class(['px-3 py-1.5 rounded-[10px] text-xs font-bold cursor-pointer', 'bg-brand-300 text-white' => $y === $year, 'text-ink-muted hover:text-ink' => $y !== $year])>{{ $y }}</button>
            @endforeach
        </form>
        <p class="text-sm text-ink-muted">Locked months are read-only for everyone, including uploads. Unlock to correct, then lock again. Every change is audited.</p>
    </div>

    <section class="mt-5 card !p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">Scope</th>
                        @foreach ($monthNames as $m)<th class="th text-center">{{ $m }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-card-muted/60">
                        <th scope="row" class="td !pl-[22px] text-left font-bold">All companies</th>
                        @foreach (range(1, 12) as $m)
                            @php $locked = $groupLocked($m); @endphp
                            <td class="td text-center !px-1">
                                <form method="POST" action="{{ route('admin.periods.update') }}"
                                      data-confirm="{{ $locked ? 'Unlock' : 'Lock' }} {{ $monthNames[$m - 1] }} {{ $year }} for all companies? Company-specific settings for that month are cleared.">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="month" value="{{ $m }}">
                                    <input type="hidden" name="locked" value="{{ $locked ? 0 : 1 }}">
                                    <button @class(['icon-tile size-9 mx-auto cursor-pointer transition', 'bg-banner text-white' => $locked, 'bg-card border border-line text-ink-muted hover:text-brand-300' => ! $locked])
                                            aria-label="{{ $locked ? 'Unlock' : 'Lock' }} {{ $monthNames[$m - 1] }} for all companies">
                                        <x-icon :name="$locked ? 'lock' : 'unlock'" class="size-4" />
                                    </button>
                                </form>
                            </td>
                        @endforeach
                    </tr>
                    @foreach ($companies as $c)
                        <tr>
                            <th scope="row" class="td !pl-[22px] text-left whitespace-nowrap">
                                <span class="flex items-center gap-2"><span class="size-2.5 rounded-full" style="background: {{ $c->color }}"></span>{{ $c->name }}</span>
                            </th>
                            @foreach (range(1, 12) as $m)
                                @php $st = $companyState($c->id, $m); @endphp
                                <td class="td text-center !px-1">
                                    <form method="POST" action="{{ route('admin.periods.update') }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="month" value="{{ $m }}">
                                        <input type="hidden" name="company_id" value="{{ $c->id }}"><input type="hidden" name="locked" value="{{ $st['locked'] ? 0 : 1 }}">
                                        <button @class(['icon-tile size-8 mx-auto cursor-pointer transition',
                                                'bg-navy-700 text-white' => $st['locked'],
                                                'text-ink-muted hover:text-brand-300' => ! $st['locked'],
                                                'ring-2 ring-warn' => $st['override']])
                                                title="{{ $st['override'] ? 'Company-specific setting' : 'Follows All companies' }}"
                                                aria-label="{{ $st['locked'] ? 'Unlock' : 'Lock' }} {{ $monthNames[$m - 1] }} for {{ $c->name }}">
                                            <x-icon :name="$st['locked'] ? 'lock' : 'unlock'" class="size-3.5" />
                                        </button>
                                    </form>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="px-[22px] py-3 text-xs text-ink-muted border-t border-line">
            <span class="inline-block size-3 rounded ring-2 ring-warn align-middle mr-1"></span> Company-specific override of the group setting.
        </p>
    </section>
</x-layouts.app>
