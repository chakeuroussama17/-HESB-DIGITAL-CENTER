@props(['kpi', 'icon' => 'chart'])
@php
    use App\Support\Money;

    $pct = $kpi['pct'] !== null ? (float) $kpi['pct'] : null;   // display only
    $fill = $pct === null ? 0 : max(0, min(100, $pct));
    $status = $kpi['status'];
    $stroke = match ($status) { 'good' => '#48BB78', 'near' => '#ED8936', 'bad' => '#E53E3E', default => '#A0AEC0' };
    $tone = match ($status) { 'good' => 'text-good', 'near' => 'text-warn', 'bad' => 'text-bad', default => 'text-ink-muted' };
    $fmt = fn ($v) => $kpi['isPercent'] ? ($v === null ? '–' : $v.'%') : Money::format($v);
    $delta = $kpi['delta'];
    // A plan of zero or a loss (common for PBT) has no meaningful '% of AMP':
    // show the gap to plan instead and colour the whole arc by status.
    $noRatio = $pct === null && $status !== 'none';
    if ($noRatio) { $fill = 100; }
@endphp
<article {{ $attributes->merge(['class' => 'card flex flex-col']) }}>
    <header class="flex items-start justify-between gap-3">
        <div>
            <p class="eyebrow">{{ $kpi['label'] }} <span class="font-normal">· YTD</span></p>
            <p class="mt-1 text-lg font-bold tabular">{{ $kpi['isPercent'] ? $fmt($kpi['actual']) : 'RM '.$fmt($kpi['actual']) }}</p>
        </div>
        <span class="icon-tile size-[45px] bg-brand-300 text-white"><x-icon :name="$icon" class="size-5" /></span>
    </header>

    <div class="relative mx-auto mt-3 w-full max-w-[220px]">
        <svg viewBox="0 0 200 112" class="w-full" role="img"
             aria-label="{{ $kpi['label'] }} at {{ $pct === null ? 'no' : $kpi['pct'].'%' }} of AMP">
            <path d="M 16 100 A 84 84 0 0 1 184 100" fill="none" stroke="currentColor" class="text-line" stroke-width="14" stroke-linecap="round" pathLength="100"/>
            @if (($pct !== null || $noRatio) && $fill > 0)
                <path d="M 16 100 A 84 84 0 0 1 184 100" fill="none" stroke="{{ $stroke }}" stroke-width="14" stroke-linecap="round"
                      pathLength="100" stroke-dasharray="{{ $fill }} 100"/>
            @endif
            {{-- 100% plan marker --}}
            <line x1="184" y1="92" x2="184" y2="108" stroke="currentColor" class="text-ink-muted" stroke-width="2"/>
        </svg>
        <div class="absolute inset-x-0 bottom-0 text-center">
            <p class="text-2xl font-bold tabular {{ $tone }}">
                @if ($noRatio)
                    {{ Money::cmp($delta, '0') >= 0 ? '+' : '' }}{{ $kpi['isPercent'] ? $delta : Money::format($delta) }}
                @else
                    {{ $pct === null ? '–' : $kpi['pct'].'%' }}
                @endif
            </p>
            <p class="text-[11px] text-ink-muted">{{ $noRatio ? 'vs AMP (plan is a loss)' : 'of AMP to date' }}</p>
        </div>
    </div>

    <footer class="mt-4 pt-3 border-t border-line grid grid-cols-2 gap-2 text-xs">
        <div>
            <p class="text-ink-muted">AMP to date</p>
            <p class="font-bold tabular">{{ $fmt($kpi['amp']) }}</p>
        </div>
        <div class="text-right">
            <p class="text-ink-muted">vs AMP</p>
            @if ($delta === null)
                <p class="font-bold text-ink-muted">–</p>
            @else
                <p class="font-bold tabular {{ $tone }}">
                    {{ Money::cmp($delta, '0') >= 0 ? '+' : '' }}{{ $kpi['isPercent'] ? $delta.' pts' : Money::format($delta) }}
                </p>
            @endif
        </div>
        <p class="col-span-2 text-[11px] text-ink-muted">Full-year AMP: {{ $fmt($kpi['fullYear']) }}</p>
    </footer>
</article>
