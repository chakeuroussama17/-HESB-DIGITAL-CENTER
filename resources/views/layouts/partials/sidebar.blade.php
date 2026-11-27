@php
    use Illuminate\Support\Facades\Route;

    // [route, label, icon, active-pattern]; items whose route doesn't exist yet are skipped.
    $sections = [];
    if ($user->isCompanyUser()) {
        $sections['Company'] = [
            ['dashboard', 'Dashboard', 'home', 'dashboard'],
            ['entries.edit', 'Forecast & Actual', 'table', 'entries.*'],
            ['results.show', 'Monthly Results', 'upload', 'results.*'],
            ['amp.show', 'AMP Plan', 'target', 'amp.*'],
        ];
    } else {
        $sections['Group'] = [
            ['group.dashboard', 'Group Dashboard', 'home', 'group.dashboard'],
            ['group.compare', 'Company Comparison', 'chart', 'group.compare'],
            ['group.tracker', 'Submission Tracker', 'grid', 'group.tracker'],
        ];
        if ($user->isAdmin()) {
            $sections['Administration'] = [
                ['admin.amp.index', 'AMP Targets', 'target', 'admin.amp.*'],
                ['admin.periods.index', 'Period Locks', 'lock', 'admin.periods.*'],
                ['admin.companies.index', 'Companies', 'building', 'admin.companies.*'],
                ['admin.users.index', 'Users', 'users', 'admin.users.*'],
                ['admin.audit.index', 'Audit Log', 'clock', 'admin.audit.*'],
                ['admin.exports.index', 'Exports', 'download', 'admin.exports.*'],
            ];
        }
    }
    // Hide links (and empty headings) for pages that aren't registered.
    $sections = array_filter(array_map(
        fn ($items) => array_values(array_filter($items, fn ($i) => Route::has($i[0]))),
        $sections
    ));
    $companies = $user->isGroupLevel()
        ? \App\Models\Company::active()->orderBy('name')->get(['id', 'name', 'code', 'color'])
        : collect();
    $currentCompany = request()->route('company');
@endphp

<a href="{{ route('home') }}" class="flex items-center gap-3 px-3 pb-6">
    <span class="grid place-items-center size-9 rounded-control bg-brand-300 text-white font-black text-sm">H</span>
    <span class="leading-tight">
        <span class="block text-[13px] font-bold tracking-wide whitespace-nowrap">HESB DIGITAL CENTER</span>
        <span class="block text-[11px] text-ink-muted">Weekly Financial Flash</span>
    </span>
</a>
<div class="h-px mb-5 bg-gradient-to-r from-transparent via-line to-transparent"></div>

<nav class="flex-1 space-y-5" aria-label="Main">
    @foreach ($sections as $heading => $items)
        <div>
            <p class="px-4 mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-muted">{{ $heading }}</p>
            <ul class="space-y-1">
                @foreach ($items as [$route, $label, $icon, $pattern])
                    @php $active = request()->routeIs($pattern); @endphp
                    <li>
                        <a href="{{ route($route) }}" @class([
                            'flex items-center gap-3 rounded-card px-4 py-2.5 text-xs transition',
                            'bg-card shadow-card font-bold text-ink' => $active,
                            'text-ink-muted hover:text-ink font-semibold' => ! $active,
                        ]) @if ($active) aria-current="page" @endif>
                            <span @class(['icon-tile', 'bg-brand-300 text-white' => $active, 'bg-card text-brand-300 shadow-card' => ! $active])>
                                <x-icon :name="$icon" class="size-4" />
                            </span>
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($companies->isNotEmpty())
        <div>
            <p class="px-4 mb-2 text-[11px] font-bold uppercase tracking-wider text-ink-muted">Companies</p>
            <ul class="space-y-0.5">
                @foreach ($companies as $c)
                    @php $active = $currentCompany && (int) (is_object($currentCompany) ? $currentCompany->id : $currentCompany) === $c->id; @endphp
                    <li>
                        <a href="{{ route('group.companies.dashboard', $c) }}" @class([
                            'flex items-center gap-3 rounded-control px-4 py-1.5 text-xs transition',
                            'bg-card shadow-card font-bold text-ink' => $active,
                            'text-ink-muted hover:text-ink' => ! $active,
                        ])>
                            <span class="size-2.5 rounded-full shrink-0" style="background: {{ $c->color }}"></span>
                            <span class="truncate">{{ $c->name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</nav>

{{-- Purity's "Need help?" card, repurposed per role --}}
<div class="mt-6 rounded-card p-4 text-white bg-banner relative overflow-hidden">
    <span class="icon-tile size-9 bg-white text-brand-400 mb-3"><x-icon name="{{ $user->isCeo() ? 'eye' : 'sparkles' }}" /></span>
    @if ($user->isCeo())
        <p class="text-sm font-bold">Read-only access</p>
        <p class="text-xs text-white/70 mt-0.5">You can view every company. Editing is reserved for HESB Admin.</p>
    @elseif ($user->isAdmin())
        <p class="text-sm font-bold">HESB Admin</p>
        <p class="text-xs text-white/70 mt-0.5">Full edit rights across all companies.</p>
    @else
        <p class="text-sm font-bold">{{ $user->company?->name }}</p>
        <p class="text-xs text-white/70 mt-0.5">Upload your weekly Excel and the grid fills itself.</p>
    @endif
    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button class="w-full h-9 rounded-control bg-white text-[11px] font-bold uppercase tracking-wide text-navy-900 hover:bg-brand-50 inline-flex items-center justify-center gap-2 cursor-pointer">
            <x-icon name="logout" class="size-3.5" /> Sign out
        </button>
    </form>
</div>
