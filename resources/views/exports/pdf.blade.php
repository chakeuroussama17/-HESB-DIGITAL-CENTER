@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $table['title'] }}</title>
    <style>
        @page { margin: 22px 26px; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 8.5px; color: #2D3748; }
        .head { background: #313860; color: #fff; padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; }
        .head .eyebrow { color: #81E6D9; font-size: 8px; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; }
        .head h1 { margin: 4px 0 0; font-size: 15px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #F7FAFC; color: #718096; font-size: 7.5px; text-transform: uppercase; text-align: left; padding: 6px 5px; border-bottom: 1px solid #E2E8F0; }
        td { padding: 5px; border-bottom: 1px solid #EDF2F7; }
        .num { text-align: right; white-space: nowrap; }
        tr.derived td, tr.total td { background: #EDF2F7; font-weight: bold; }
        .foot { margin-top: 10px; color: #A0AEC0; font-size: 7.5px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="eyebrow">HESB Digital Center</div>
        <h1>{{ $table['title'] }}</h1>
    </div>
    <table>
        <thead>
            <tr>@foreach ($table['headers'] as $i => $h)<th class="{{ in_array($i, $table['numeric'], true) ? 'num' : '' }}">{{ $h }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($table['rows'] as $ri => $row)
                @php
                    $derived = in_array($row[0] ?? null, $table['derivedRows'] ?? [], true);
                    $total = ($table['boldLast'] ?? false) && $ri === count($table['rows']) - 1;
                @endphp
                <tr class="{{ $derived ? 'derived' : '' }} {{ $total ? 'total' : '' }}">
                    @foreach (array_values($row) as $i => $v)
                        @if (in_array($i, $table['numeric'], true))
                            <td class="num">{{ $v === null ? '–' : Money::format((string) $v) }}</td>
                        @else
                            <td>{{ $v }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="foot">Generated {{ now()->format('j M Y H:i') }} by {{ auth()->user()?->name }} · Amounts in RM '000 · Negative values in brackets</p>
</body>
</html>
