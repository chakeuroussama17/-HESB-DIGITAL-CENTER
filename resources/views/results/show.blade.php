@php
    use App\Models\MonthlyResult;
    use App\Support\Money;
    $user = auth()->user();
    $monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $yy = substr((string) $year, -2);
@endphp
<x-layouts.app title="Monthly Results" :section="$user->isCompanyUser() ? 'Company' : $company->name">
    <section class="bg-banner rounded-card p-6 text-white">
        <div class="flex flex-wrap items-start gap-4">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-200">Monthly results · as reported</p>
                <h2 class="mt-1 text-2xl font-bold">{{ $company->name }} · {{ $year }}</h2>
                <p class="mt-2 text-sm text-white/70 max-w-2xl">
                    Upload your monthly results sheet (Rev / GP / PBT blocks with AMP and Act per month).
                    Figures are stored exactly as in the file, and dashboards use them in place of the weekly totals.
                </p>
                @if ($lastUpload)
                    <p class="mt-2 text-xs text-white/60">Last upload: {{ $lastUpload->original_name }} · {{ $lastUpload->created_at->diffForHumans() }}</p>
                @endif
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1 rounded-control bg-white/10 p-1">
                    <a href="{{ $url('results.show', ['year' => $year - 1]) }}" class="px-2 py-1 text-xs font-bold text-white/70 hover:text-white" aria-label="Previous year">‹</a>
                    <span class="text-xs font-bold">{{ $year }}</span>
                    <a href="{{ $url('results.show', ['year' => $year + 1]) }}" class="px-2 py-1 text-xs font-bold text-white/70 hover:text-white" aria-label="Next year">›</a>
                </div>
                @if ($canUpload)
                    <form method="POST" action="{{ $url('results.upload') }}" enctype="multipart/form-data">
                        @csrf
                        <label class="btn bg-white text-navy-900 hover:bg-brand-50 cursor-pointer">
                            <x-icon name="upload" class="size-4" /> Upload results sheet
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" onchange="this.form.submit()">
                        </label>
                    </form>
                @else
                    <span class="badge bg-white/15 text-white"><x-icon name="eye" class="size-3.5" /> View only</span>
                @endif
            </div>
        </div>
    </section>

    {{-- Preview of a pending upload --}}
    @if ($preview)
        <section class="mt-6 card border-2 border-warn/60" aria-labelledby="preview-title">
            <header class="flex flex-wrap items-center gap-3">
                <span class="icon-tile size-10 bg-warn/20 text-warn"><x-icon name="upload" class="size-5" /></span>
                <div>
                    <h3 id="preview-title" class="text-lg font-bold">Check before saving: {{ $preview['file'] }}</h3>
                    <p class="text-sm text-ink-muted">
                        {{ $preview['changes'] }} {{ \Illuminate\Support\Str::plural('figure', $preview['changes']) }} will change. Blank cells in the file leave saved figures untouched.
                        @if ($preview['skippedLocked'])
                            <span class="text-bad font-bold">{{ $preview['skippedLocked'] }} actual(s) for locked months will be skipped.</span>
                        @endif
                    </p>
                </div>
                <div class="ml-auto flex gap-2">
                    <form method="POST" action="{{ $url('results.cancel') }}">@csrf<button class="btn-ghost">Discard</button></form>
                    <form method="POST" action="{{ $url('results.confirm') }}">@csrf
                        <button class="btn-primary" @disabled($preview['changes'] === 0)><x-icon name="check" class="size-4" /> Confirm &amp; save</button>
                    </form>
                </div>
            </header>
            @if ($preview['warnings'])
                <ul class="mt-3 ml-4 list-disc text-xs text-ink-soft">@foreach ($preview['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
            @endif
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm tabular">
                    <thead>
                        <tr>
                            <th class="th">Month</th><th class="th">Metric</th>
                            <th class="th text-right">AMP (saved → file)</th>
                            <th class="th text-right">Act (saved → file)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            @continue(! $row['amp']['changed'] && ! $row['actual']['changed'] && ! $row['actual']['blocked'])
                            <tr>
                                <td class="td whitespace-nowrap">{{ $monthNames[$row['month']] }}-{{ substr((string) $row['year'], -2) }}
                                    @if ($row['locked'])<x-icon name="lock" class="inline size-3 text-ink-muted" />@endif
                                </td>
                                <td class="td">{{ MonthlyResult::METRICS[$row['metric']] }}</td>
                                @foreach (['amp', 'actual'] as $kind)
                                    @php $c = $row[$kind]; @endphp
                                    <td class="td text-right whitespace-nowrap">
                                        @if ($c['changed'] || $c['blocked'])
                                            <span class="text-ink-muted line-through">{{ Money::format($c['old']) }}</span>
                                            <span class="mx-1 text-ink-muted">→</span>
                                            <span @class(['font-bold px-1.5 py-0.5 rounded', 'bg-warn/20' => $c['changed'], 'bg-bad/15 text-bad' => $c['blocked']])>{{ Money::format($c['new']) }}</span>
                                            @if ($c['blocked'])<span class="badge-bad ml-1">locked</span>@endif
                                        @else
                                            <span class="text-ink-muted">{{ Money::format($c['old'] ?? $c['new']) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Stored figures, laid out like the source sheet --}}
    @if ($metrics)
        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            @foreach ($metrics as $metric)
                @php
                    $rows = $results[$metric];
                    $closed = $rows->filter(fn ($r) => $r->actual !== null);
                    $lastClosed = $closed->max('month');
                    $ytdAmp = $closed->isEmpty() ? null : Money::add(...$rows->filter(fn ($r) => $r->month <= $lastClosed)->pluck('amp')->all());
                    $ytdAct = $closed->isEmpty() ? null : Money::add(...$closed->pluck('actual')->all());
                    $fyAmp = Money::add(...$rows->pluck('amp')->all());
                @endphp
                <section class="card !p-0 overflow-hidden">
                    <header class="px-[22px] pt-[22px] pb-3 flex items-center gap-3">
                        <span class="icon-tile size-9 bg-brand-300 text-white"><x-icon name="chart" class="size-4" /></span>
                        <h3 class="text-base font-bold">{{ MonthlyResult::METRICS[$metric] }}</h3>
                        @if ($metric === 'ebitda')<span class="badge-muted ml-auto">stored · not on dashboards</span>@endif
                    </header>
                    <table class="w-full text-sm tabular">
                        <thead>
                            <tr><th class="th !pl-[22px]">Month</th><th class="th text-right">AMP</th><th class="th text-right">Act</th><th class="th text-right !pr-[22px]">Var</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($monthNames as $m => $name)
                                @php
                                    $r = $rows->get($m);
                                    $var = $r && $r->actual !== null && $r->amp !== null ? Money::sub($r->actual, $r->amp) : null;
                                @endphp
                                <tr>
                                    <td class="td !pl-[22px] !py-2">{{ $name }}-{{ $yy }}</td>
                                    <td class="td !py-2 text-right text-ink-soft">{{ Money::format($r?->amp, false, '') }}</td>
                                    <td class="td !py-2 text-right font-semibold">{{ Money::format($r?->actual, false, '') }}</td>
                                    <td class="td !py-2 text-right !pr-[22px] {{ $var === null ? '' : (Money::cmp($var, '0') >= 0 ? 'text-good' : 'text-bad') }}">{{ Money::format($var, false, '') }}</td>
                                </tr>
                            @endforeach
                            @if ($lastClosed)
                                @php $ytdVar = Money::sub($ytdAct, $ytdAmp); @endphp
                                <tr class="bg-derived font-bold">
                                    <td class="td !pl-[22px] !py-2">YTD {{ $monthNames[$lastClosed] }}-{{ $yy }}</td>
                                    <td class="td !py-2 text-right">{{ Money::format($ytdAmp) }}</td>
                                    <td class="td !py-2 text-right">{{ Money::format($ytdAct) }}</td>
                                    <td class="td !py-2 text-right !pr-[22px] {{ Money::cmp($ytdVar, '0') >= 0 ? 'text-good' : 'text-bad' }}">
                                        {{ Money::format($ytdVar) }}
                                        @php $p = Money::percent($ytdVar, $ytdAmp); @endphp
                                        @if ($p !== null)<span class="block text-[11px] font-normal">{{ $p }}%</span>@endif
                                    </td>
                                </tr>
                            @endif
                            <tr class="bg-card-muted/60">
                                <td class="td !pl-[22px] !py-2 font-bold">FY {{ $year }}</td>
                                <td class="td !py-2 text-right font-bold">{{ Money::format($fyAmp) }}</td>
                                <td class="td !py-2 text-right">{{ Money::format($ytdAct) }}</td>
                                <td class="td !py-2 !pr-[22px]"></td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            @endforeach
        </div>
    @elseif (! $preview)
        <section class="mt-6 card text-center py-12">
            <span class="icon-tile size-12 mx-auto bg-card-muted text-brand-300"><x-icon name="upload" class="size-6" /></span>
            <p class="mt-3 font-bold">No results uploaded for {{ $year }} yet</p>
            <p class="text-sm text-ink-muted">
                Expected layout: a block titled e.g. <span class="font-mono">HA Rev</span> with columns
                <span class="font-mono">AMP</span> · <span class="font-mono">Act</span> · <span class="font-mono">Var</span>
                and rows <span class="font-mono">Jan-26</span> … <span class="font-mono">Dec-26</span>. Same for GP and PBT. YTD rows are ignored.
            </p>
        </section>
    @endif
</x-layouts.app>
