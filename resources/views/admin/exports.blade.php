@php
    $cards = [
        ['summary', 'chart', 'Group performance summary', 'YTD Revenue, GP, PBT and margin vs AMP for every company plus the group total.', ['xlsx', 'csv', 'pdf'], false, false],
        ['pnl', 'document', 'Company P&L', 'Every line, actual per month, FY actual vs FY AMP. Board-pack ready.', ['xlsx', 'csv', 'pdf'], true, false],
        ['entries', 'table', 'Weekly entries (raw)', 'Every weekly Act / Fc value with who changed it last. For audit and analysis.', ['xlsx', 'csv'], 'optional', true],
    ];
@endphp
<x-layouts.app title="Exports" section="Administration">
    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ($cards as [$type, $icon, $title, $desc, $formats, $needsCompany, $hasMonth])
            <form method="GET" action="{{ route('admin.exports.download', $type) }}" class="card flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <span class="icon-tile size-11 bg-brand-300 text-white"><x-icon :name="$icon" class="size-5" /></span>
                    <h3 class="text-base font-bold">{{ $title }}</h3>
                </div>
                <p class="text-sm text-ink-muted">{{ $desc }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label !text-xs" for="{{ $type }}-year">Year</label>
                        <select id="{{ $type }}-year" name="year" class="field-sm">@foreach ($years as $y)<option>{{ $y }}</option>@endforeach</select>
                    </div>
                    @if ($hasMonth)
                        <div>
                            <label class="label !text-xs" for="{{ $type }}-month">Month</label>
                            <select id="{{ $type }}-month" name="month" class="field-sm"><option value="">Whole year</option>
                                @foreach ($months as $i => $m)<option value="{{ $i + 1 }}">{{ $m }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    @if ($needsCompany)
                        <div class="col-span-2">
                            <label class="label !text-xs" for="{{ $type }}-company">Company</label>
                            <select id="{{ $type }}-company" name="company" class="field-sm" @if ($needsCompany === true) required @endif>
                                @if ($needsCompany === 'optional')<option value="">All companies</option>@endif
                                @foreach ($companies as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                </div>
                <div class="mt-auto flex flex-wrap gap-2 pt-2">
                    @foreach ($formats as $f)
                        <button name="format" value="{{ $f }}" class="{{ $loop->first ? 'btn-primary' : 'btn-ghost' }}">
                            <x-icon name="download" class="size-4" /> {{ strtoupper($f) }}
                        </button>
                    @endforeach
                </div>
            </form>
        @endforeach
    </div>
    <p class="mt-6 text-xs text-ink-muted">Exports are logged in the audit trail. CSV text cells are protected against spreadsheet formula injection.</p>
</x-layouts.app>
