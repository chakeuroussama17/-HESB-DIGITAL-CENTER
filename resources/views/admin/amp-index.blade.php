@php use App\Support\Money; @endphp
<x-layouts.app title="AMP Targets" section="Administration">
    <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="card !p-1 flex gap-1">
            @foreach ($years as $y)
                <button name="year" value="{{ $y }}" @class(['px-3 py-1.5 rounded-[10px] text-xs font-bold cursor-pointer', 'bg-brand-300 text-white' => $y === $year, 'text-ink-muted hover:text-ink' => $y !== $year])>{{ $y }}</button>
            @endforeach
        </form>
        <p class="text-sm text-ink-muted">Set each company's monthly plan per line, in the grid or by importing Excel/CSV.</p>
    </div>

    <div class="mt-5 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($companies as $row)
            @php $c = $row['company']; $done = $row['months'] === 12; @endphp
            <a href="{{ route('group.amp.show', ['company' => $c->id, 'year' => $year]) }}" class="card group hover:shadow-pop transition">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center size-10 rounded-control text-white text-xs font-black" style="background: {{ $c->color }}">{{ mb_substr($c->code, 0, 2) }}</span>
                    <div class="min-w-0">
                        <p class="font-bold truncate">{{ $c->name }}</p>
                        <p class="text-xs text-ink-muted">{{ $row['updated'] ? 'Updated '.\Carbon\Carbon::parse($row['updated'])->diffForHumans() : 'No plan yet' }}</p>
                    </div>
                    <span class="ml-auto {{ $done ? 'badge-good' : ($row['months'] ? 'badge-warn' : 'badge-muted') }}">{{ $row['months'] }}/12</span>
                </div>
                <div class="mt-4 h-1.5 rounded-full bg-line overflow-hidden"><div class="h-full bg-brand-300" style="width: {{ $row['months'] / 12 * 100 }}%"></div></div>
                <div class="mt-4 flex items-end justify-between">
                    <div>
                        <p class="eyebrow">FY revenue plan</p>
                        <p class="text-lg font-bold tabular">RM {{ Money::format($row['revenue']) }}</p>
                    </div>
                    <span class="text-xs font-bold text-brand-300 inline-flex items-center gap-1">Enter AMP <x-icon name="arrow-right" class="size-3.5" /></span>
                </div>
            </a>
        @endforeach
    </div>
</x-layouts.app>
