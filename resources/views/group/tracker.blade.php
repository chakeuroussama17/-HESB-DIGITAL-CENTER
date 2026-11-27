@php
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $pill = [
        'submitted' => ['badge-good', 'check', 'Submitted'],
        'pending' => ['badge-warn', 'clock', 'Pending'],
        'missed' => ['badge-muted', 'x', 'Not sent'],
        'not-due' => ['badge-muted', null, 'Not due'],
        'open' => ['badge-muted', null, 'Open'],
    ];
@endphp
<x-layouts.app title="Submission Tracker" section="Group">
    <div class="flex flex-wrap items-end gap-4">
        <form method="GET" class="card !p-1 flex flex-wrap items-center gap-1">
            <select name="year" class="h-8 rounded-[10px] bg-transparent px-2 text-xs font-bold outline-none" onchange="this.form.submit()" aria-label="Year">
                @foreach ($years as $y)<option @selected($y === $year)>{{ $y }}</option>@endforeach
            </select>
            @foreach ($monthNames as $i => $m)
                <button name="month" value="{{ $i + 1 }}" @class([
                    'px-3 py-1.5 rounded-[10px] text-xs font-bold cursor-pointer',
                    'bg-brand-300 text-white' => $month === $i + 1,
                    'text-ink-muted hover:text-ink' => $month !== $i + 1,
                ])>{{ $m }}</button>
            @endforeach
        </form>
        <div class="ml-auto flex gap-3">
            <div class="card !py-3 !px-4"><p class="eyebrow">Submitted</p><p class="text-lg font-bold text-good">{{ $totals['submitted'] }}</p></div>
            <div class="card !py-3 !px-4"><p class="eyebrow">Pending</p><p class="text-lg font-bold text-warn">{{ $totals['pending'] }}</p></div>
        </div>
    </div>

    <section class="mt-5 card !p-0 overflow-hidden">
        <header class="px-[22px] pt-[22px] pb-3">
            <h3 class="text-lg font-bold">{{ $monthName }}</h3>
            <p class="text-sm text-ink-muted">A = actual, F = forecast. A week counts as submitted once Revenue is filled in.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">Company</th>
                        @foreach ($weeks as $w => $info)
                            <th class="th text-center border-l">Wk{{ $w }} <span class="block font-normal normal-case">{{ $info['range'] }}</span></th>
                        @endforeach
                        <th class="th !pr-[22px]"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $c)
                        <tr class="hover:bg-card-muted/50">
                            <th scope="row" class="td !pl-[22px] text-left">
                                <span class="flex items-center gap-2 font-bold">
                                    <span class="size-2.5 rounded-full" style="background: {{ $c->color }}"></span>{{ $c->name }}
                                    @if ($locked[$c->id])<x-icon name="lock" class="size-3.5 text-ink-muted" title="Month locked" />@endif
                                </span>
                            </th>
                            @foreach ($weeks as $w => $info)
                                <td class="td border-l">
                                    <div class="flex flex-col items-center gap-1">
                                        @foreach (['actual' => 'A', 'forecast' => 'F'] as $kind => $letter)
                                            @php [$cls, $icon, $text] = $pill[$matrix[$c->id][$w][$kind]]; @endphp
                                            <span class="{{ $cls }} w-[104px] justify-start" title="{{ ucfirst($kind) }}: {{ $text }}">
                                                <span class="opacity-60">{{ $letter }}</span>
                                                @if ($icon)<x-icon :name="$icon" class="size-3" />@endif
                                                {{ $text }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            @endforeach
                            <td class="td !pr-[22px] text-right">
                                <a href="{{ route('group.entries.edit', ['company' => $c->id, 'year' => $year, 'month' => $month]) }}" class="btn-ghost !h-8 !px-3">Open grid</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 card !p-0 overflow-hidden">
        <header class="px-[22px] pt-[22px] pb-3">
            <h3 class="text-lg font-bold">{{ $year }} at a glance</h3>
            <p class="text-sm text-ink-muted">Share of finished weeks with actuals submitted</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">Company</th>
                        @foreach ($monthNames as $i => $m)<th class="th text-center">{{ $m }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $c)
                        <tr>
                            <th scope="row" class="td !pl-[22px] text-left font-bold whitespace-nowrap">{{ $c->name }}</th>
                            @foreach (range(1, 12) as $m)
                                @php $p = $overview[$c->id][$m]; @endphp
                                <td class="td text-center !px-1">
                                    <a href="{{ request()->fullUrlWithQuery(['month' => $m]) }}" @class([
                                        'inline-grid place-items-center w-12 h-7 rounded-lg text-[11px] font-bold',
                                        'bg-card-muted text-ink-muted' => $p === null,
                                        'bg-good/20 text-good' => $p === 100,
                                        'bg-warn/20 text-warn' => $p !== null && $p < 100 && $p >= 50,
                                        'bg-bad/20 text-bad' => $p !== null && $p < 50,
                                    ])>{{ $p === null ? '·' : $p.'%' }}</a>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
