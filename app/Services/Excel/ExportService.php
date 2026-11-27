<?php

namespace App\Services\Excel;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Services\Finance\FinancialCalculator;
use App\Services\Finance\PerformanceService;
use App\Support\Money;
use App\Support\Week;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds export tables ({title, headers, rows, numeric columns}) and writes them as
 * XLSX, CSV or PDF. Amounts stay exact strings until the writer.
 */
final class ExportService
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private FinancialCalculator $calc, private PerformanceService $performance) {}

    /** Raw weekly entries: one row per company / week / line. */
    public function entries(?Company $company, int $year, ?int $month): array
    {
        $rows = Entry::query()
            ->with(['company:id,name,code', 'line:id,code,label', 'editor:id,name'])
            ->when($company, fn ($q) => $q->where('company_id', $company->id))
            ->where('year', $year)
            ->when($month, fn ($q) => $q->where('month', $month))
            ->orderBy('company_id')->orderBy('month')->orderBy('week')->orderBy('financial_line_id')
            ->get()
            ->map(fn (Entry $e) => [
                $e->company->code, $e->company->name, $e->year, $e->month, $e->week,
                $e->line->code, $e->line->label, $e->actual, $e->forecast,
                $e->editor?->name, $e->updated_at?->format('Y-m-d H:i'),
            ])->all();

        return [
            'title' => 'Weekly entries '.($company?->name ?? 'all companies').' '.$year.($month ? '-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT) : ''),
            'headers' => ['Company code', 'Company', 'Year', 'Month', 'Week', 'Line code', 'Line', 'Actual', 'Forecast', 'Updated by', 'Updated at'],
            'numeric' => [7, 8],
            'rows' => $rows,
        ];
    }

    /** Group summary: YTD vs AMP for every active company plus the group total. */
    public function summary(int $year): array
    {
        $companies = Company::active()->orderBy('name')->get();
        $row = fn (string $name, array $k) => [
            $name,
            $k['total_revenue']['actual'], $k['total_revenue']['amp'], $k['total_revenue']['pct'],
            $k['gross_profit']['actual'], $k['gross_profit']['amp'], $k['gross_profit']['pct'],
            $k['pbt']['actual'], $k['pbt']['amp'], $k['pbt']['pct'],
            $k['gross_margin']['actual'], $k['gross_margin']['amp'],
        ];

        $rows = $companies->map(fn ($c) => $row($c->name, $this->performance->headline($c, $year)['kpis']))->all();
        $rows[] = $row('HESB Group', $this->performance->dashboard($companies, $year)['kpis']);

        return [
            'title' => "Group performance YTD {$year} (RM '000)",
            'headers' => ['Company', 'Revenue', 'Revenue AMP', 'Revenue % AMP', 'Gross Profit', 'GP AMP', 'GP % AMP', 'PBT', 'PBT AMP', 'PBT % AMP', 'GM %', 'GM % AMP'],
            'numeric' => range(1, 11),
            'rows' => $rows,
            'boldLast' => true,
        ];
    }

    /** Company P&L: every line, actual per month, FY actual, FY AMP, variance. */
    public function pnl(Company $company, int $year): array
    {
        $lines = FinancialLine::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->orderBy('sort')->orderBy('id')->get();
        $code = $lines->pluck('code', 'id');

        $act = [];
        Entry::where(['company_id' => $company->id, 'year' => $year])->whereNotNull('actual')
            ->get(['month', 'financial_line_id', 'actual'])
            ->each(function ($e) use (&$act, $code) {
                $c = $code[$e->financial_line_id];
                $act[$e->month][$c] = Money::add($act[$e->month][$c] ?? null, $e->actual);
            });
        // FY AMP for reference, and AMP to date (same proration as the dashboards:
        // finished months in full, the current month by completed weeks).
        $today = CarbonImmutable::today();
        $asOf = $year < $today->year ? CarbonImmutable::create($year + 1, 1, 1) : ($year > $today->year ? CarbonImmutable::create($year, 1, 1) : $today);
        $amp = [];
        $ampToDate = [];
        AmpTarget::where(['company_id' => $company->id, 'year' => $year])->get(['month', 'financial_line_id', 'amount'])
            ->each(function ($a) use (&$amp, &$ampToDate, $code, $year, $asOf) {
                $c = $code[$a->financial_line_id];
                $amp[$c] = Money::add($amp[$c] ?? null, $a->amount);
                $done = Week::completed($year, $a->month, $asOf);
                $count = Week::count($year, $a->month);
                if ($done > 0) {
                    $part = $done === $count ? $a->amount : Money::normalise(bcdiv(bcmul($a->amount, (string) $done, 6), (string) $count, 6));
                    $ampToDate[$c] = Money::add($ampToDate[$c] ?? null, $part);
                }
            });

        $months = [];
        $fy = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = $this->calc->compute($lines, $act[$m] ?? []);
            foreach (($act[$m] ?? []) as $c => $v) {
                $fy[$c] = Money::add($fy[$c] ?? null, $v);
            }
        }
        $fyD = $this->calc->compute($lines, $fy);
        $ampD = $this->calc->compute($lines, $amp);
        $toDateD = $this->calc->compute($lines, $ampToDate);

        $rows = $lines->filter(fn ($l) => $l->is_active || isset($fy[$l->code]))->map(function (FinancialLine $l) use ($months, $fyD, $ampD, $toDateD) {
            $r = [$l->label];
            for ($m = 1; $m <= 12; $m++) {
                $r[] = $months[$m][$l->code] ?? null;
            }
            $r[] = $fyD[$l->code] ?? null;
            $r[] = $toDateD[$l->code] ?? null;
            $r[] = $this->calc->variance($fyD[$l->code] ?? null, $toDateD[$l->code] ?? null);
            $r[] = $ampD[$l->code] ?? null;

            return $r;
        })->values()->all();

        return [
            'title' => "{$company->name} · Actual P&L {$year} (RM '000)",
            'headers' => array_merge(['Line'], self::MONTHS, ['YTD Actual', 'AMP to date', 'Var vs AMP', 'FY AMP']),
            'numeric' => range(1, 16),
            'rows' => $rows,
            'derivedRows' => $lines->filter(fn ($l) => ! $l->isInput())->pluck('label')->all(),
        ];
    }

    public function download(array $table, string $format, string $filename): Response|StreamedResponse
    {
        return match ($format) {
            'csv' => $this->csv($table, "{$filename}.csv"),
            'pdf' => Pdf::loadView('exports.pdf', ['table' => $table])->setPaper('a4', 'landscape')->download("{$filename}.pdf"),
            default => $this->xlsx($table, "{$filename}.xlsx"),
        };
    }

    private function csv(array $table, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($table) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
            fputcsv($out, $table['headers']);
            foreach ($table['rows'] as $row) {
                fputcsv($out, array_map(fn ($v, $i) => in_array($i, $table['numeric'], true) ? $v : $this->safeText($v), $row, array_keys($row)));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $table, string $name): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Export');
        $sheet->setCellValue('A1', $table['title'])->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->fromArray($table['headers'], null, 'A3');
        $lastCol = Coordinate::stringFromColumnIndex(count($table['headers']));
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '313860']],
        ]);

        $r = 4;
        foreach ($table['rows'] as $row) {
            foreach (array_values($row) as $i => $v) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
                if ($v === null) {
                    continue;
                }
                if (in_array($i, $table['numeric'], true) && is_numeric($v)) {
                    $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING); // never a formula
                }
            }
            if (in_array($row[0] ?? null, $table['derivedRows'] ?? [], true) || (($table['boldLast'] ?? false) && $r === 3 + count($table['rows']))) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
            }
            $r++;
        }
        foreach ($table['numeric'] as $i) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getStyle("{$col}4:{$col}{$r}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        }
        foreach (range(1, count($table['headers'])) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sheet->freezePane('B4');

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Neutralise CSV formula injection in text cells (=, +, -, @ at the start). */
    private function safeText(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    public static function months(): array
    {
        return self::MONTHS;
    }
}
