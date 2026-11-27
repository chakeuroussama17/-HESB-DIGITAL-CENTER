@php
    use App\Support\Money;
    $fmt = fn ($v, $pct) => $v === null ? null : ($pct ? $v.'%' : Money::format($v));
    $pendingFc = count($flash['pendingForecast']) > 0;
@endphp
<section class="card !p-0 overflow-hidden {{ $class ?? '' }}">
    <header class="px-[22px] pt-[22px] pb-3 flex flex-wrap items-start gap-3">
        <div>
            <h3 class="text-lg font-bold">Financial flash</h3>
            <p class="text-sm text-ink-muted">Weekly cadence · last finished week and the next two forecasts</p>
        </div>
        <div class="ml-auto flex flex-wrap gap-2">
            @if ($flash['pendingActual'])
                <span class="badge-bad"><x-icon name="alert" class="size-3.5" /> Actual pending</span>
            @endif
            @if ($pendingFc)
                <span class="badge-warn"><x-icon name="clock" class="size-3.5" /> Forecast Submission Pending</span>
            @else
                <span class="badge-good"><x-icon name="check" class="size-3.5" /> Forecasts in</span>
            @endif
        </div>
    </header>
    <div class="overflow-x-auto">
        <table class="w-full text-sm tabular">
            <thead>
                <tr>
                    <th class="th !pl-[22px] whitespace-nowrap">Metric</th>
                    <th class="th text-right whitespace-nowrap">{{ $flash['labels']['actual'] }}</th>
                    <th class="th text-right whitespace-nowrap">{{ $flash['labels']['fc1'] }}</th>
                    <th class="th text-right whitespace-nowrap">{{ $flash['labels']['fc2'] }}</th>
                    <th class="th !pr-[22px] whitespace-nowrap">Trend <span class="normal-case font-normal">(8 wks)</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($flash['rows'] as $code => $row)
                    <tr>
                        <th scope="row" class="td !pl-[22px] text-left font-bold whitespace-nowrap">{{ $row['label'] }}</th>
                        <td class="td text-right font-semibold">
                            {{ $fmt($row['actual'], $row['isPercent']) ?? '' }}
                            @if ($row['actual'] === null)<span class="badge-muted">No actual</span>@endif
                        </td>
                        @foreach (['fc1', 'fc2'] as $k)
                            <td class="td text-right text-ink-soft">
                                @if ($row[$k] === null)
                                    <span class="badge-warn" title="Forecast Submission Pending">Pending</span>
                                @else
                                    {{ $fmt($row[$k], $row['isPercent']) }}
                                @endif
                            </td>
                        @endforeach
                        <td class="td !pr-[22px] whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <x-sparkline :values="$row['trend']" />
                                @if ($row['change'] !== null)
                                    @php $up = Money::cmp($row['change'], '0') >= 0; @endphp
                                    <span class="inline-flex items-center gap-1 text-xs font-bold {{ $up ? 'text-good' : 'text-bad' }}">
                                        <x-icon :name="$up ? 'trend-up' : 'trend-down'" class="size-3.5" />
                                        {{ $up ? '+' : '' }}{{ $row['change'] }}{{ $row['isPercent'] ? ' pts' : '%' }}
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (($showCompanies ?? false) && ($flash['pendingForecast'] || $flash['pendingActual']))
        <footer class="px-[22px] py-3 border-t border-line text-xs text-ink-muted flex flex-wrap gap-x-6 gap-y-1">
            @if ($flash['pendingActual'])
                <p><span class="font-bold text-bad">Actual pending:</span> {{ collect($flash['pendingActual'])->pluck('name')->join(', ') }}</p>
            @endif
            @if ($flash['pendingForecast'])
                <p><span class="font-bold text-warn">Forecast pending:</span> {{ collect($flash['pendingForecast'])->pluck('name')->join(', ') }}</p>
            @endif
        </footer>
    @endif
</section>
