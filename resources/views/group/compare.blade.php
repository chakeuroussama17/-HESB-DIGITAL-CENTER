@php
    use App\Support\Money;
    $months = ['Year to date', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $tone = fn ($pct) => $pct === null ? 'text-ink-muted' : ((float) $pct >= 100 ? 'text-good' : ((float) $pct >= 95 ? 'text-warn' : 'text-bad'));
@endphp
<x-layouts.app title="Company Comparison" section="Group">
    <form method="GET" class="card flex flex-wrap items-end gap-4">
        <div>
            <label class="label !text-xs" for="f-year">Year</label>
            <select id="f-year" name="year" class="field-sm w-28">@foreach ($years as $y)<option @selected($y === $year)>{{ $y }}</option>@endforeach</select>
        </div>
        <div>
            <label class="label !text-xs" for="f-month">Period</label>
            <select id="f-month" name="month" class="field-sm w-44">@foreach ($months as $i => $m)<option value="{{ $i }}" @selected($i === $month)>{{ $m }}</option>@endforeach</select>
        </div>
        <div>
            <span class="label !text-xs">Rank by</span>
            <div class="flex flex-wrap gap-1 rounded-control bg-card-muted p-1">
                @foreach ($metrics as $code => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="metric" value="{{ $code }}" class="peer sr-only" @checked($code === $metric)>
                        <span class="block px-3 py-1.5 rounded-[10px] text-xs font-bold text-ink-muted peer-checked:bg-card peer-checked:text-ink peer-checked:shadow-card">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <button class="btn-primary">Apply</button>
        <p class="ml-auto text-xs text-ink-muted max-w-xs">{{ $month ? 'Full-month AMP for '.$months[$month].'.' : 'YTD compares with AMP for finished weeks only.' }}</p>
    </form>

    <section class="mt-6 card !p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]" rowspan="2">Rank</th>
                        <th class="th" rowspan="2">Company</th>
                        @foreach ($metrics as $code => $label)
                            <th colspan="3" @class(['th text-center border-l', '!text-brand-400 bg-brand-300/10' => $code === $metric])>{{ $label }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($metrics as $code => $label)
                            <th @class(['th text-right border-l !py-1.5', 'bg-brand-300/10' => $code === $metric])>Actual</th>
                            <th @class(['th text-right !py-1.5', 'bg-brand-300/10' => $code === $metric])>AMP</th>
                            <th @class(['th text-right !py-1.5', 'bg-brand-300/10' => $code === $metric])>{{ $code === 'gross_margin' ? 'Δ pts' : '% AMP' }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr class="hover:bg-card-muted/50">
                            <td class="td !pl-[22px]">
                                <span @class(['grid place-items-center size-7 rounded-lg text-xs font-bold',
                                    'bg-brand-300 text-white' => $i === 0, 'bg-card-muted' => $i > 0])>{{ $i + 1 }}</span>
                            </td>
                            <td class="td">
                                <a href="{{ route('group.companies.dashboard', $r['company']) }}" class="flex items-center gap-2 font-bold whitespace-nowrap hover:text-brand-300">
                                    <span class="size-2.5 rounded-full" style="background: {{ $r['company']->color }}"></span>{{ $r['company']->name }}
                                </a>
                            </td>
                            @foreach ($r['metrics'] as $code => $m)
                                @php $hl = $code === $metric ? 'bg-brand-300/5' : ''; @endphp
                                @if ($m['isPercent'])
                                    <td class="td text-right border-l {{ $hl }}">{{ $m['actual'] === null ? '–' : $m['actual'].'%' }}</td>
                                    <td class="td text-right text-ink-muted {{ $hl }}">{{ $m['amp'] === null ? '–' : $m['amp'].'%' }}</td>
                                    <td class="td text-right font-bold {{ $hl }} {{ $m['var'] === null ? 'text-ink-muted' : (Money::cmp($m['var'], '0') >= 0 ? 'text-good' : 'text-bad') }}">{{ $m['var'] === null ? '–' : (Money::cmp($m['var'], '0') >= 0 ? '+' : '').$m['var'] }}</td>
                                @else
                                    <td class="td text-right border-l {{ $hl }}">{{ Money::format($m['actual']) }}</td>
                                    <td class="td text-right text-ink-muted {{ $hl }}">{{ Money::format($m['amp']) }}</td>
                                    <td class="td text-right font-bold {{ $hl }} {{ $tone($m['pct']) }}">{{ $m['pct'] === null ? '–' : $m['pct'].'%' }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-5">
        <section class="card xl:col-span-3">
            <h3 class="text-lg font-bold">{{ $metrics[$metric] }}: Actual vs AMP</h3>
            <p class="text-sm text-ink-muted">{{ $months[$month] }} {{ $year }}, in rank order</p>
            <div class="relative mt-4 h-[320px]"><canvas id="compare-bar" role="img" aria-label="Bar chart of actual versus AMP by company"></canvas></div>
        </section>
        <section class="card xl:col-span-2">
            <h3 class="text-lg font-bold">Shape of performance</h3>
            <p class="text-sm text-ink-muted">% of AMP on every metric (100 = on plan)</p>
            <div class="relative mt-4 h-[320px]"><canvas id="compare-radar" role="img" aria-label="Radar chart of percent of AMP by metric"></canvas></div>
        </section>
    </div>

    <script type="application/json" id="compare-data">@json($chart)</script>
    @push('scripts')
        @vite('resources/js/compare.js')
    @endpush
</x-layouts.app>
