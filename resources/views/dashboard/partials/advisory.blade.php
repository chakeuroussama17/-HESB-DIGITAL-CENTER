@php
    $styles = [
        'bad' => ['alert', 'bg-bad/15 text-bad'],
        'warn' => ['clock', 'bg-warn/15 text-warn'],
        'info' => ['info', 'bg-info/15 text-info'],
        'good' => ['check', 'bg-good/15 text-good'],
    ];
@endphp
<section class="card {{ $class ?? '' }}" aria-labelledby="guru-title">
    <header class="flex items-center gap-3">
        <span class="icon-tile size-10 bg-banner text-brand-200"><x-icon name="sparkles" class="size-5" /></span>
        <div>
            <h3 id="guru-title" class="text-lg font-bold leading-tight">Finance Guru Advisory</h3>
            <p class="text-xs text-ink-muted">Automatic insights from this week's numbers</p>
        </div>
    </header>
    <ol class="mt-4 space-y-3">
        @foreach ($advice as $tip)
            @php [$icon, $cls] = $styles[$tip['level']]; @endphp
            <li class="flex gap-3">
                <span class="icon-tile {{ $cls }}"><x-icon :name="$icon" class="size-4" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-bold">{{ $tip['title'] }}</p>
                    <p class="text-xs text-ink-muted mt-0.5">{{ $tip['detail'] }}</p>
                    @if ($tip['action'] === 'entries' && ($entriesUrl ?? null))
                        <a href="{{ $entriesUrl }}" class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-brand-300 hover:underline">
                            Go to Forecast &amp; Actual <x-icon name="arrow-right" class="size-3" />
                        </a>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
