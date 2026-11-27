@php
    use App\Support\Money;
    $user = auth()->user();
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
@endphp
<x-layouts.app title="AMP {{ $year }}" :section="$user->isCompanyUser() ? 'Company' : $company->name">
    <section class="bg-banner rounded-card p-6 text-white">
        <div class="flex flex-wrap items-start gap-4">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-brand-200">Annual Management Plan</p>
                <h2 class="mt-1 text-2xl font-bold">{{ $company->name }} · {{ $year }}</h2>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="badge bg-white/15 text-white">{{ $filled }}/12 months planned</span>
                    @if ($canEdit)
                        <span class="badge bg-brand-300/25 text-brand-100"><x-icon name="unlock" class="size-3.5" /> Editable (HESB Admin)</span>
                    @else
                        <span class="badge bg-white/15 text-white"><x-icon name="eye" class="size-3.5" /> View only. Set by HESB Admin</span>
                    @endif
                    @if ($stagedCount)
                        <span class="badge bg-warn/30 text-white">{{ $stagedCount }} values from upload, not saved yet</span>
                    @endif
                </div>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1 rounded-control bg-white/10 p-1">
                    <a href="{{ $url('amp.show', ['year' => $year - 1]) }}" class="px-2 py-1 text-xs font-bold text-white/70 hover:text-white" aria-label="Previous year">‹</a>
                    <span class="text-xs font-bold">{{ $year }}</span>
                    <a href="{{ $url('amp.show', ['year' => $year + 1]) }}" class="px-2 py-1 text-xs font-bold text-white/70 hover:text-white" aria-label="Next year">›</a>
                </div>
                <a href="{{ $url('amp.template', ['year' => $year]) }}" class="btn bg-white/10 text-white hover:bg-white/20 border border-white/15">
                    <x-icon name="download" class="size-4" /> Excel
                </a>
                @if ($canEdit)
                    <form method="POST" action="{{ route('group.amp.import', ['company' => $company->id, 'year' => $year]) }}" enctype="multipart/form-data">
                        @csrf
                        <label class="btn bg-white text-navy-900 hover:bg-brand-50 cursor-pointer">
                            <x-icon name="upload" class="size-4" /> Import Excel / CSV
                            <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" data-upload-input>
                        </label>
                    </form>
                @endif
            </div>
        </div>
    </section>

    @if ($warnings)
        <div class="mt-4 card !py-3 border-l-4 border-warn">
            <ul class="ml-4 list-disc text-xs text-ink-soft">@foreach ($warnings as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $canEdit ? route('group.amp.update', ['company' => $company->id, 'year' => $year]) : '#' }}" class="mt-5" data-amp-grid>
        @csrf @method('PUT')
        <div class="card !p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-0 text-sm tabular">
                    <thead>
                        <tr>
                            <th class="th sticky left-0 z-10 bg-card min-w-[200px] !pl-5">Line item</th>
                            @foreach ($months as $m)<th class="th text-right">{{ $m }}</th>@endforeach
                            <th class="th text-right bg-card-muted !pr-5">FY {{ $year }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $line = $row['line']; $cost = $line->isCost(); @endphp
                            <tr class="{{ $line->isInput() ? '' : 'bg-derived font-bold' }}" data-row="{{ $line->code }}" data-cost="{{ $cost ? 1 : 0 }}">
                                <th scope="row" class="td sticky left-0 z-[5] text-left !pl-5 {{ $line->isInput() ? 'bg-card font-normal' : 'bg-derived' }}">{{ $line->label }}</th>
                                @foreach ($row['months'] as $m => $cell)
                                    <td class="td !p-1">
                                        @if ($line->isInput() && $canEdit)
                                            <input type="text" inputmode="decimal" name="amp[{{ $line->id }}][{{ $m }}]"
                                                   value="{{ Money::format($cell['value'], false, '') }}" data-code="{{ $line->code }}" data-month="{{ $m }}"
                                                   aria-label="{{ $line->label }} {{ $months[$m - 1] }}"
                                                   @class(['w-[88px] h-8 rounded-lg px-2 text-right text-[13px] bg-transparent border border-transparent hover:border-line focus:border-brand-300 focus:bg-field outline-none',
                                                           '!bg-warn/20 !border-warn' => $cell['staged']])>
                                        @else
                                            <span class="block w-[88px] px-2 text-right text-[13px]" data-derived="{{ $line->code }}" data-month="{{ $m }}">{{ Money::format($cell['value'], $cost) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="td text-right bg-card-muted/60 font-semibold !pr-5" data-total="{{ $line->code }}">{{ Money::format($row['total'], $cost) }}</td>
                            </tr>
                        @endforeach
                        <tr class="text-ink-muted">
                            <th scope="row" class="td sticky left-0 bg-card text-left !pl-5 font-normal italic">Gross margin %</th>
                            @foreach ($grossMargin as $i => $gm)<td class="td text-right text-xs" data-gm="{{ $i + 1 }}">{{ $gm === null ? '–' : $gm.'%' }}</td>@endforeach
                            <td class="td text-right text-xs bg-card-muted/60 !pr-5" data-gm="total">{{ $totalMargin === null ? '–' : $totalMargin.'%' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @if ($canEdit)
            <div class="sticky bottom-4 mt-4 flex justify-end">
                <div class="card !py-3 !px-4 shadow-pop flex items-center gap-4">
                    <p class="text-xs text-ink-muted">Blank a cell to remove that target. Every change is audited.</p>
                    <button class="btn-primary"><x-icon name="check" class="size-4" /> Save AMP {{ $year }}</button>
                </div>
            </div>
        @endif
    </form>

    <script type="application/json" id="grid-definition">@json($definition)</script>
    @push('scripts')
        @vite('resources/js/amp.js')
    @endpush
</x-layouts.app>
