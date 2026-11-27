@props(['values' => [], 'labels' => [], 'width' => 96, 'height' => 28])
@php
    // Display-only geometry; nulls break the line.
    $nums = array_map(fn ($v) => $v === null ? null : (float) $v, $values);
    $present = array_filter($nums, fn ($v) => $v !== null);
    $min = $present ? min($present) : 0;
    $max = $present ? max($present) : 1;
    $span = ($max - $min) ?: 1;
    $n = max(count($nums) - 1, 1);
    $segments = [];
    $current = [];
    foreach ($nums as $i => $v) {
        if ($v === null) { if ($current) { $segments[] = $current; $current = []; } continue; }
        $current[] = round($i / $n * ($width - 4) + 2, 1).','.round($height - 3 - ($v - $min) / $span * ($height - 6), 1);
    }
    if ($current) { $segments[] = $current; }
    $last = end($present);
    $prev = count($present) > 1 ? array_values($present)[count($present) - 2] : null;
    $up = $prev === null ? null : $last >= $prev;
@endphp
<svg width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" {{ $attributes->merge(['class' => 'overflow-visible']) }} aria-hidden="true">
    @foreach ($segments as $pts)
        <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#4FD1C5" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
    @endforeach
    @if ($segments)
        @php $lastSeg = $segments[array_key_last($segments)]; [$lx, $ly] = explode(',', $lastSeg[array_key_last($lastSeg)]); @endphp
        <circle cx="{{ $lx }}" cy="{{ $ly }}" r="2.5" fill="{{ $up === false ? '#E53E3E' : '#48BB78' }}"/>
    @endif
</svg>
