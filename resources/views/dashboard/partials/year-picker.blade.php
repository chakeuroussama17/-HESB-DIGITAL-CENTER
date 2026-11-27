<form method="GET" class="flex items-center gap-2">
    <label for="year" class="text-xs text-white/70">Year</label>
    <select id="year" name="year" onchange="this.form.submit()"
            class="h-9 rounded-control bg-white/10 border border-white/15 text-white text-sm font-bold px-3 outline-none focus:ring-2 focus:ring-brand-300/40">
        @foreach ($years as $y)
            <option value="{{ $y }}" @selected($y === $year) class="text-ink bg-card">{{ $y }}</option>
        @endforeach
    </select>
    <noscript><button class="btn bg-white/10 text-white">Go</button></noscript>
</form>
