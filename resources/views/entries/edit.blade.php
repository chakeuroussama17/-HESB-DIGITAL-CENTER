@php
    use App\Enums\LineGroup;
    use App\Support\Money;

    $user = auth()->user();
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $inputRows = $rows->filter(fn ($r) => $r['line']->isInput())->values();
    $title = 'Forecast & Actual';
@endphp

<x-layouts.app :title="$title" :section="$user->isCompanyUser() ? 'Company' : $company->name">
    {{-- Banner --}}
    <section class="bg-banner rounded-card p-6 text-white relative overflow-hidden">
        <div class="flex flex-wrap items-start gap-4">
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-200">Manage Forecast &amp; Actual</p>
                <h2 class="mt-1 text-2xl font-bold">{{ $company->name }} · {{ $monthName }}</h2>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($locked)
                        <span class="badge bg-white/15 text-white"><x-icon name="lock" class="size-3.5" /> Locked, read-only</span>
                    @elseif ($canEdit)
                        <span class="badge bg-brand-300/25 text-brand-100"><x-icon name="unlock" class="size-3.5" /> Open for entry</span>
                    @else
                        <span class="badge bg-white/15 text-white"><x-icon name="eye" class="size-3.5" /> View only</span>
                    @endif
                    @if ($stagedCount)
                        <span class="badge bg-warn/30 text-white"><x-icon name="upload" class="size-3.5" /> {{ $stagedCount }} cells from upload, not saved yet</span>
                    @endif
                    <span class="text-xs text-white/60">Amounts in RM '000 · costs entered as positive numbers</span>
                </div>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <a href="{{ $url('entries.template', ['year' => $year, 'month' => $month]) }}"
                   class="btn bg-white/10 text-white hover:bg-white/20 border border-white/15">
                    <x-icon name="download" class="size-4" /> Excel template
                </a>
                @if ($canEdit)
                    <form method="POST" action="{{ $url('entries.upload', ['year' => $year, 'month' => $month]) }}" enctype="multipart/form-data" data-upload-form>
                        @csrf
                        <label class="btn bg-white text-navy-900 hover:bg-brand-50 cursor-pointer">
                            <x-icon name="upload" class="size-4" /> Upload Excel
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" data-upload-input>
                        </label>
                    </form>
                @endif
            </div>
        </div>
    </section>

    {{-- Period picker --}}
    <nav class="mt-5 flex flex-wrap items-center gap-2" aria-label="Month">
        <div class="flex items-center gap-1 card !p-1">
            <a href="{{ $url('entries.edit', ['year' => $year - 1, 'month' => $month]) }}" class="px-2 py-1.5 text-ink-muted hover:text-ink text-xs font-bold" aria-label="Previous year">‹</a>
            <span class="px-1 text-xs font-bold">{{ $year }}</span>
            <a href="{{ $url('entries.edit', ['year' => $year + 1, 'month' => $month]) }}" class="px-2 py-1.5 text-ink-muted hover:text-ink text-xs font-bold" aria-label="Next year">›</a>
        </div>
        <div class="flex flex-wrap gap-1 card !p-1">
            @foreach ($monthNames as $i => $m)
                <a href="{{ $url('entries.edit', ['year' => $year, 'month' => $i + 1]) }}" @class([
                    'px-3 py-1.5 rounded-[10px] text-xs font-bold transition',
                    'bg-brand-300 text-white' => $month === $i + 1,
                    'text-ink-muted hover:text-ink hover:bg-card-muted' => $month !== $i + 1,
                ]) @if ($month === $i + 1) aria-current="page" @endif>{{ $m }}</a>
            @endforeach
        </div>
        <div class="ml-auto hidden md:flex items-center gap-3 text-[11px] text-ink-muted">
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded border border-line bg-field"></span> Input</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-derived"></span> Calculated</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-warn/30 ring-1 ring-warn"></span> From upload</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded border-t-2 border-brand-300"></span> This week</span>
        </div>
    </nav>

    @if ($uploadWarnings)
        <div class="mt-4 card !py-3 border-l-4 border-warn">
            <p class="text-sm font-bold flex items-center gap-2"><x-icon name="alert" class="size-4 text-warn" /> Some rows in the file were skipped</p>
            <ul class="mt-1 ml-6 list-disc text-xs text-ink-soft space-y-0.5">
                @foreach ($uploadWarnings as $w)<li>{{ $w }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Grid --}}
    <form method="POST" action="{{ $canEdit ? $url('entries.update', ['year' => $year, 'month' => $month]) : '#' }}"
          class="mt-4" id="entry-form" data-grid @if (! $canEdit) data-readonly @endif>
        @csrf
        @method('PUT')
        <div class="card !p-0 overflow-hidden">
            <div class="overflow-x-auto max-h-[70vh]">
                <table class="w-full border-separate border-spacing-0 text-sm tabular">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-card">
                            <th rowspan="2" class="th sticky left-0 z-20 bg-card min-w-[210px] !pl-5">Line item</th>
                            @foreach ($weeks as $w)
                                <th colspan="2" @class([
                                    'th text-center !normal-case !tracking-normal border-l',
                                    'border-t-2 !border-t-brand-300 bg-brand-300/10 !text-brand-400' => $w['isCurrent'],
                                ])>
                                    <span class="block text-[11px] font-bold">Wk{{ $w['number'] }}@if ($w['isCurrent']) · this week @endif</span>
                                    <span class="block text-[10px] font-normal">{{ $w['range'] }}</span>
                                </th>
                            @endforeach
                            <th rowspan="2" class="th text-right border-l bg-card-muted">Total Act</th>
                            <th rowspan="2" class="th text-right bg-card-muted">AMP</th>
                            <th rowspan="2" class="th text-right bg-card-muted">Var</th>
                            <th rowspan="2" class="th text-right bg-card-muted !pr-5">Var %</th>
                        </tr>
                        <tr class="bg-card">
                            @foreach ($weeks as $w)
                                <th @class(['th text-right border-l !py-1.5', 'bg-brand-300/10' => $w['isCurrent']])>Act</th>
                                <th @class(['th text-right !py-1.5', 'bg-brand-300/10' => $w['isCurrent']])>AMP</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $line = $row['line'];
                                $isInput = $line->isInput();
                                $isCost = $line->isCost();
                                $rowCls = $isInput ? '' : 'bg-derived font-bold';
                            @endphp
                            <tr class="{{ $rowCls }} group/row" data-row="{{ $line->code }}" data-cost="{{ $isCost ? 1 : 0 }}" data-input="{{ $isInput ? 1 : 0 }}">
                                <th scope="row" class="td sticky left-0 z-[5] text-left !pl-5 {{ $isInput ? 'bg-card font-normal' : 'bg-derived font-bold' }}">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate">{{ $line->label }}</span>
                                        @if ($line->isCustom())
                                            <span class="badge-brand !px-1.5 !py-0.5 !text-[9px]">custom</span>
                                        @endif
                                        @if ($line->isCustom() && $canManageLines && ! $locked)
                                            <button type="submit" form="delete-line-{{ $line->id }}"
                                                    class="ml-auto opacity-0 group-hover/row:opacity-100 focus:opacity-100 text-ink-muted hover:text-bad cursor-pointer"
                                                    aria-label="Remove {{ $line->label }}">
                                                <x-icon name="trash" class="size-3.5" />
                                            </button>
                                        @endif
                                    </span>
                                </th>

                                @foreach ($weeks as $w)
                                    @foreach (['actual', 'forecast'] as $kind)
                                        @php
                                            $value = $row['weeks'][$w['number']][$kind];
                                            $staged = $row['weeks'][$w['number']][$kind.'_staged'];
                                            $cellCls = ($kind === 'actual' ? 'border-l ' : '').($w['isCurrent'] ? 'bg-brand-300/5 ' : '');
                                        @endphp
                                        <td class="td !p-1 {{ $cellCls }}">
                                            @if ($isInput)
                                                @php $disabled = ! $canEdit || ($kind === 'actual' && $w['isFuture']); @endphp
                                                <input type="text" inputmode="decimal" autocomplete="off"
                                                       name="cells[{{ $line->id }}][{{ $w['number'] }}][{{ $kind }}]"
                                                       value="{{ Money::format($value, false, '') }}"
                                                       data-code="{{ $line->code }}" data-week="{{ $w['number'] }}" data-kind="{{ $kind }}"
                                                       aria-label="{{ $line->label }} week {{ $w['number'] }} {{ $kind }}"
                                                       @if ($staged) data-staged @endif
                                                       @if ($disabled) disabled title="{{ $kind === 'actual' && $w['isFuture'] ? 'Week has not started' : 'Read only' }}" @endif
                                                       @class([
                                                           'w-[92px] h-8 rounded-lg px-2 text-right text-[13px] bg-transparent border outline-none transition',
                                                           'border-transparent hover:border-line focus:border-brand-300 focus:bg-field focus:ring-2 focus:ring-brand-300/20' => ! $disabled,
                                                           'border-transparent text-ink-muted cursor-not-allowed' => $disabled,
                                                           '!bg-warn/20 !border-warn' => $staged,
                                                           '!border-bad' => $errors->has("cells.{$line->id}.{$w['number']}.{$kind}"),
                                                           $kind === 'forecast' ? 'text-ink-soft' : '',
                                                       ])>
                                            @else
                                                <span class="block w-[92px] px-2 text-right text-[13px]" data-derived="{{ $line->code }}" data-week="{{ $w['number'] }}" data-kind="{{ $kind }}">{{ Money::format($value, $isCost) }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                @endforeach

                                <td class="td text-right border-l bg-card-muted/60 font-semibold" data-total="{{ $line->code }}" data-kind="actual">{{ Money::format($row['totalActual'], $isCost) }}</td>
                                <td class="td text-right bg-card-muted/60">{{ Money::format($row['amp'], $isCost) }}</td>
                                <td @class(['td text-right bg-card-muted/60', 'text-good' => $row['favourable'] === true, 'text-bad' => $row['favourable'] === false]) data-var="{{ $line->code }}">{{ Money::format($row['variance']) }}</td>
                                <td @class(['td text-right bg-card-muted/60 !pr-5', 'text-good' => $row['favourable'] === true, 'text-bad' => $row['favourable'] === false]) data-varpct="{{ $line->code }}">{{ $row['variancePct'] === null ? '–' : $row['variancePct'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($canEdit)
            {{-- Sticky save bar --}}
            <div class="sticky bottom-4 z-20 mt-4 flex justify-end">
                <div class="card !py-3 !px-4 flex items-center gap-4 shadow-pop">
                    <p class="text-xs text-ink-muted" data-dirty-label>
                        @if ($stagedCount) Uploaded values are waiting to be saved. @else Tip: paste a block straight from Excel into any cell. @endif
                    </p>
                    <button type="submit" class="btn-primary" data-save>
                        <x-icon name="check" class="size-4" /> Save {{ $monthName }}
                    </button>
                </div>
            </div>
        @endif
    </form>

    {{-- Custom row forms live outside the grid form --}}
    @foreach ($rows as $row)
        @if ($row['line']->isCustom())
            <form id="delete-line-{{ $row['line']->id }}" method="POST" class="hidden"
                  action="{{ $url('lines.destroy', ['line' => $row['line']->id]) }}"
                  data-confirm="Remove the row &quot;{{ $row['line']->label }}&quot;? Rows with data are archived, not deleted.">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach

    @if ($canManageLines && ! $locked)
        <form method="POST" action="{{ $url('lines.store') }}" class="mt-4 card flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label for="new-line-label" class="label !text-xs">+ Add Row</label>
                <input id="new-line-label" name="label" required maxlength="60" value="{{ old('label') }}" class="field-sm" placeholder="e.g. Tooling Amortisation">
            </div>
            <div class="min-w-[220px]">
                <label for="new-line-group" class="label !text-xs">Rolls into</label>
                <select id="new-line-group" name="group" required class="field-sm">
                    @foreach (LineGroup::cases() as $g)
                        <option value="{{ $g->value }}" @selected(old('group') === $g->value)>{{ $g->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-ghost h-10"><x-icon name="plus" class="size-4" /> Add row</button>
        </form>
    @endif

    <script type="application/json" id="grid-definition">@json($definition)</script>
    @push('scripts')
        @vite('resources/js/grid.js')
    @endpush
</x-layouts.app>
