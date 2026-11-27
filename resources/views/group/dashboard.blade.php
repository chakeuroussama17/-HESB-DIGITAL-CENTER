@php
    use App\Support\Money;
    $pending = count($flash['pendingForecast']);
@endphp
<x-layouts.app title="Group Dashboard" section="Group">
    <section class="bg-banner rounded-card p-6 sm:p-8 text-white relative overflow-hidden">
        <svg class="absolute right-0 bottom-0 h-full opacity-20 pointer-events-none" viewBox="0 0 400 200" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 150 C90 110 150 170 230 110 S340 50 400 70 L400 200 L0 200Z" fill="#4FD1C5"/>
        </svg>
        <div class="relative flex flex-wrap items-end gap-6">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-200">HESB Group · consolidated</p>
                <h2 class="mt-1 text-2xl sm:text-3xl font-bold">{{ $companies->count() }} companies, one view</h2>
                <p class="mt-2 text-sm text-white/70">Performance {{ $year }} · as of {{ $asOf->subDay()->format('j M Y') }} · RM '000</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="badge {{ $pending ? 'bg-warn/30' : 'bg-good/30' }} text-white">
                        <x-icon name="{{ $pending ? 'clock' : 'check' }}" class="size-3.5" />
                        {{ $companies->count() - $pending }}/{{ $companies->count() }} forecasts in
                    </span>
                    @if ($flash['pendingActual'])
                        <span class="badge bg-bad/40 text-white">{{ count($flash['pendingActual']) }} missing {{ $flash['labels']['actual'] }}</span>
                    @endif
                    @if (auth()->user()->isCeo())
                        <span class="badge bg-white/15 text-white"><x-icon name="eye" class="size-3.5" /> Read-only</span>
                    @endif
                </div>
            </div>
            <div class="ml-auto">@include('dashboard.partials.year-picker')</div>
        </div>
    </section>

    <section class="mt-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4" aria-label="Group key figures">
        <x-gauge :kpi="$kpis['total_revenue']" icon="chart" />
        <x-gauge :kpi="$kpis['gross_profit']" icon="scale" />
        <x-gauge :kpi="$kpis['pbt']" icon="trend-up" />
        <x-gauge :kpi="$kpis['gross_margin']" icon="target" />
    </section>

    {{-- Scoreboard --}}
    <section class="mt-6 card !p-0 overflow-hidden">
        <header class="px-[22px] pt-[22px] pb-3 flex flex-wrap items-center gap-3">
            <div>
                <h3 class="text-lg font-bold">Company scoreboard</h3>
                <p class="text-sm text-ink-muted">Year to date vs AMP, ranked by revenue achievement</p>
            </div>
            <a href="{{ route('group.compare', ['year' => $year]) }}" class="ml-auto btn-ghost">Compare in depth <x-icon name="arrow-right" class="size-4" /></a>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular">
                <thead>
                    <tr>
                        <th class="th !pl-[22px]">#</th>
                        <th class="th">Company</th>
                        <th class="th text-right">Revenue</th>
                        <th class="th w-[180px]">% of AMP</th>
                        <th class="th text-right">Gross Profit</th>
                        <th class="th text-right">PBT</th>
                        <th class="th text-right">GM %</th>
                        <th class="th">This week</th>
                        <th class="th !pr-[22px]"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scoreboard as $i => $s)
                        @php
                            $k = $s['kpis'];
                            $pct = $k['total_revenue']['pct'];
                            $bar = $pct === null ? 0 : min(100, max(0, (float) $pct));
                            $tone = fn ($st) => match ($st) { 'good' => 'text-good', 'near' => 'text-warn', 'bad' => 'text-bad', default => 'text-ink-muted' };
                            $fcPending = count($s['flash']['pendingForecast']) > 0;
                            $actPending = count($s['flash']['pendingActual']) > 0;
                        @endphp
                        <tr class="hover:bg-card-muted/50">
                            <td class="td !pl-[22px] text-ink-muted font-bold">{{ $i + 1 }}</td>
                            <td class="td">
                                <a href="{{ route('group.companies.dashboard', $s['company']) }}" class="flex items-center gap-2 font-bold hover:text-brand-300">
                                    <span class="size-2.5 rounded-full" style="background: {{ $s['company']->color }}"></span>{{ $s['company']->name }}
                                </a>
                            </td>
                            <td class="td text-right font-semibold">{{ Money::format($k['total_revenue']['actual']) }}</td>
                            <td class="td">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 rounded-full bg-line overflow-hidden">
                                        <div class="h-full rounded-full {{ ['good' => 'bg-good', 'near' => 'bg-warn', 'bad' => 'bg-bad'][$k['total_revenue']['status']] ?? 'bg-ink-muted' }}" style="width: {{ $bar }}%"></div>
                                    </div>
                                    <span class="text-xs font-bold w-14 text-right {{ $tone($k['total_revenue']['status']) }}">{{ $pct === null ? '–' : $pct.'%' }}</span>
                                </div>
                            </td>
                            <td class="td text-right {{ $tone($k['gross_profit']['status']) }}">{{ Money::format($k['gross_profit']['actual']) }}</td>
                            <td class="td text-right {{ $tone($k['pbt']['status']) }}">{{ Money::format($k['pbt']['actual']) }}</td>
                            <td class="td text-right {{ $tone($k['gross_margin']['status']) }}">{{ $k['gross_margin']['actual'] === null ? '–' : $k['gross_margin']['actual'].'%' }}</td>
                            <td class="td">
                                @if ($actPending)<span class="badge-bad">Actual pending</span>
                                @elseif ($fcPending)<span class="badge-warn">Forecast Submission Pending</span>
                                @else<span class="badge-good">Up to date</span>@endif
                            </td>
                            <td class="td !pr-[22px] text-right">
                                <a href="{{ route('group.companies.dashboard', $s['company']) }}" class="text-ink-muted hover:text-brand-300" aria-label="Open {{ $s['company']->name }}"><x-icon name="arrow-right" class="size-4" /></a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        @include('dashboard.partials.flash', ['class' => 'xl:col-span-2', 'showCompanies' => true])
        @include('dashboard.partials.advisory', ['entriesUrl' => route('group.tracker')])
    </div>

    <div class="mt-6">
        @include('dashboard.partials.chart')
    </div>

    @push('scripts')
        @vite('resources/js/dashboard.js')
    @endpush
</x-layouts.app>
