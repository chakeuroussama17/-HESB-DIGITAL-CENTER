@php
    use App\Support\PortalRoute;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $entriesUrl = PortalRoute::to('entries.edit', $company, ['year' => $flash['fc1']['year'], 'month' => $flash['fc1']['month']]);
    $ampUrl = Route::has('amp.show') ? PortalRoute::to('amp.show', $company, ['year' => $year]) : null;
    $pending = count($flash['pendingForecast']) > 0;
@endphp

<x-layouts.app :title="$user->isCompanyUser() ? 'Dashboard' : $company->name" :section="$user->isCompanyUser() ? 'Company' : 'Companies'">
    {{-- Gradient banner --}}
    <section class="bg-banner rounded-card p-6 sm:p-8 text-white relative overflow-hidden">
        <svg class="absolute right-0 bottom-0 h-full opacity-20 pointer-events-none" viewBox="0 0 400 200" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 160 C80 120 140 170 220 120 S340 60 400 80 L400 200 L0 200Z" fill="#4FD1C5"/>
        </svg>
        <div class="relative flex flex-wrap items-end gap-6">
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-200">
                    {{ $user->isCompanyUser() ? 'Welcome back, '.\Illuminate\Support\Str::before($user->name, ' ') : 'Company drill-down' }}
                </p>
                <h2 class="mt-1 text-2xl sm:text-3xl font-bold flex items-center gap-3">
                    <span class="size-3 rounded-full" style="background: {{ $company->color }}"></span>{{ $company->name }}
                </h2>
                <p class="mt-2 text-sm text-white/70">
                    Performance {{ $year }} · as of {{ $asOf->subDay()->format('j M Y') }} · RM '000
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($pending)
                        <span class="badge bg-warn/30 text-white"><x-icon name="clock" class="size-3.5" /> Forecast Submission Pending</span>
                    @endif
                    @if ($flash['pendingActual'])
                        <span class="badge bg-bad/40 text-white"><x-icon name="alert" class="size-3.5" /> {{ $flash['labels']['actual'] }} missing</span>
                    @endif
                    @if (! $pending && ! $flash['pendingActual'])
                        <span class="badge bg-good/30 text-white"><x-icon name="check" class="size-3.5" /> Submissions up to date</span>
                    @endif
                </div>
            </div>
            <div class="ml-auto">
                @include('dashboard.partials.year-picker')
            </div>
        </div>
    </section>

    {{-- Gauges --}}
    <section class="mt-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key figures">
        <x-gauge :kpi="$kpis['total_revenue']" icon="chart" />
        <x-gauge :kpi="$kpis['gross_profit']" icon="scale" />
        <x-gauge :kpi="$kpis['pbt']" icon="trend-up" />
        <x-gauge :kpi="$kpis['gross_margin']" icon="target" />
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        @include('dashboard.partials.chart', ['class' => 'xl:col-span-2'])

        {{-- Shortcut cards --}}
        <div class="grid gap-6 content-start">
            <a href="{{ $entriesUrl }}" class="card group flex items-center gap-4 hover:shadow-pop transition">
                <span class="icon-tile size-12 bg-brand-300 text-white"><x-icon name="table" class="size-6" /></span>
                <div class="min-w-0">
                    <p class="eyebrow">{{ $user->can('enterData', $company) ? 'Weekly submission' : 'Weekly data' }}</p>
                    <p class="text-base font-bold">Manage Forecast &amp; Actual</p>
                    <p class="text-xs text-ink-muted">{{ $user->can('enterData', $company) ? 'Key in or upload Excel. The grid fills itself.' : 'Open the weekly grid (read only).' }}</p>
                </div>
                <x-icon name="arrow-right" class="ml-auto size-5 text-ink-muted group-hover:text-brand-300 transition" />
            </a>
            <a href="{{ PortalRoute::to('results.show', $company, ['year' => $year]) }}" class="card group flex items-center gap-4 hover:shadow-pop transition">
                <span class="icon-tile size-12 bg-brand-300 text-white"><x-icon name="upload" class="size-6" /></span>
                <div class="min-w-0">
                    <p class="eyebrow">Monthly close</p>
                    <p class="text-base font-bold">{{ $user->can('uploadResults', $company) ? 'Upload monthly results' : 'Monthly results' }}</p>
                    <p class="text-xs text-ink-muted">Rev / GP / PBT sheet with AMP and Actual, used as reported.</p>
                </div>
                <x-icon name="arrow-right" class="ml-auto size-5 text-ink-muted group-hover:text-brand-300 transition" />
            </a>
            @if ($ampUrl)
                <a href="{{ $ampUrl }}" class="card group flex items-center gap-4 hover:shadow-pop transition">
                    <span class="icon-tile size-12 bg-banner text-brand-200"><x-icon name="target" class="size-6" /></span>
                    <div class="min-w-0">
                        <p class="eyebrow">Annual Management Plan</p>
                        <p class="text-base font-bold">{{ $user->isAdmin() ? 'Enter AMP' : 'View AMP' }}</p>
                        <p class="text-xs text-ink-muted">Monthly targets per line for {{ $year }}</p>
                    </div>
                    <x-icon name="arrow-right" class="ml-auto size-5 text-ink-muted group-hover:text-brand-300 transition" />
                </a>
            @endif
            <a href="{{ PortalRoute::to('entries.template', $company, ['year' => $flash['fc1']['year'], 'month' => $flash['fc1']['month']]) }}"
               class="card group flex items-center gap-4 hover:shadow-pop transition">
                <span class="icon-tile size-12 bg-card-muted text-brand-300"><x-icon name="download" class="size-6" /></span>
                <div class="min-w-0">
                    <p class="eyebrow">Offline</p>
                    <p class="text-base font-bold">Excel template</p>
                    <p class="text-xs text-ink-muted">Pre-filled for {{ \Carbon\CarbonImmutable::create($flash['fc1']['year'], $flash['fc1']['month'])->format('F Y') }}</p>
                </div>
                <x-icon name="arrow-right" class="ml-auto size-5 text-ink-muted group-hover:text-brand-300 transition" />
            </a>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        @include('dashboard.partials.flash', ['class' => 'xl:col-span-2'])
        @include('dashboard.partials.advisory', ['entriesUrl' => $entriesUrl])
    </div>

    @push('scripts')
        @vite('resources/js/dashboard.js')
    @endpush
</x-layouts.app>
