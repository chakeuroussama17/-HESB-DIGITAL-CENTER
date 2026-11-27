@php $chartId = 'chart-'.\Illuminate\Support\Str::random(6); @endphp
<section class="card {{ $class ?? '' }}" data-combo-chart="{{ $chartId }}">
    <header class="flex flex-wrap items-start gap-3">
        <div>
            <h3 class="text-lg font-bold">Monthly performance {{ $year }}</h3>
            <p class="text-sm text-ink-muted">AMP vs Actual</p>
        </div>
        <div class="ml-auto flex flex-wrap gap-1 rounded-control bg-card-muted p-1" role="tablist" aria-label="Metric">
            @foreach ($chart['metrics'] as $code => $label)
                <button type="button" role="tab" data-metric="{{ $code }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-[10px] text-xs font-bold text-ink-muted aria-selected:bg-card aria-selected:text-ink aria-selected:shadow-card cursor-pointer">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </header>
    <div class="relative mt-4 h-[300px]">
        <canvas id="{{ $chartId }}" aria-label="Monthly AMP, actual and forecast chart" role="img"></canvas>
    </div>
    <script type="application/json" data-chart-data="{{ $chartId }}">@json($chart)</script>
</section>
